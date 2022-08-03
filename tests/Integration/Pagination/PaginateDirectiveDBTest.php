<?php

namespace Tests\Integration\Pagination;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Mockery;
use Tests\DBTestCase;
use Tests\TestsScoutEngine;
use Tests\Utils\Models\Comment;
use Tests\Utils\Models\Post;
use Tests\Utils\Models\User;

final class PaginateDirectiveDBTest extends DBTestCase
{
    use TestsScoutEngine;

    public function testPaginate(): void
    {
        factory(User::class, 3)->create();

        $this->schema = /** @lang GraphQL */ '
        type User {
            id: ID!
        }

        type Query {
            users: [User!]! @paginate
        }
        ';

        $this->graphQL(/** @lang GraphQL */ '
        {
            users(first: 2) {
                paginatorInfo {
                    count
                    total
                    currentPage
                }
                data {
                    id
                }
            }
        }
        ')->assertJson([
            'data' => [
                'users' => [
                    'paginatorInfo' => [
                        'count' => 2,
                        'total' => 3,
                        'currentPage' => 1,
                    ],
                    'data' => [],
                ],
            ],
        ])->assertJsonCount(2, 'data.users.data');
    }

    public function testSpecifyCustomBuilder(): void
    {
        factory(User::class, 2)->create();

        $this->schema = /** @lang GraphQL */ <<<GRAPHQL
        type User {
            id: ID!
        }

        type Query {
            users: [User!]! @paginate(builder: "{$this->qualifyTestResolver('builder')}")
        }
GRAPHQL;

        // The custom builder is supposed to change the sort order
        $this->graphQL(/** @lang GraphQL */ '
        {
            users(first: 1) {
                data {
                    id
                }
            }
        }
        ')->assertJson([
            'data' => [
                'users' => [
                    'data' => [
                        [
                            'id' => '2',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testSpecifyCustomBuilderForRelation(): void
    {
        $user = factory(User::class)->create();
        assert($user instanceof User);

        $posts = factory(Post::class, 2)->create();
        $user->posts()->saveMany($posts);

        $this->schema = /** @lang GraphQL */ <<<GRAPHQL
        type Post {
            id: ID!
        }

        type User {
            id: ID!
            posts: [Post!]! @paginate(builder: "{$this->qualifyTestResolver('builderForRelation')}")
        }

        type Query {
            user(id: ID! @eq): User @find
        }
GRAPHQL;

        // The custom builder is supposed to change the sort order
        $this->graphQL(/** @lang GraphQL */ "
        {
            user(id: {$user->id}) {
                posts(first: 10) {
                    data {
                        id
                    }
                }
            }
        }
        ")->assertJson([
            'data' => [
                'user' => [
                    'posts' => [
                        'data' => [
                            [
                                'id' => '2',
                            ],
                            [
                                'id' => '1',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testSpecifyCustomBuilderForScoutBuilder(): void
    {
        $this->setUpScoutEngine();

        /** @var \Tests\Utils\Models\Post $postA */
        $postA = factory(Post::class)->create([
            'title' => 'great title',
        ]);
        /** @var \Tests\Utils\Models\Post $postB */
        $postB = factory(Post::class)->create([
            'title' => 'Really great title',
        ]);
        factory(Post::class)->create([
            'title' => 'bad title',
        ]);

        $this->engine->shouldReceive('map')
            ->andReturn(
                new EloquentCollection([$postA, $postB])
            )
            ->once();

        $this->engine->shouldReceive('paginate')
            ->with(
                Mockery::any(),
                Mockery::any(),
                Mockery::not('page')
            )
            ->andReturn(new EloquentCollection([$postA, $postB]))
            ->once();

        $this->schema = /** @lang GraphQL */ <<<GRAPHQL
        type Post {
            id: ID!
        }

        type Query {
            posts: [Post!]! @paginate(builder: "{$this->qualifyTestResolver('builderForScoutBuilder')}")
        }
GRAPHQL;

        $this->graphQL(/** @lang GraphQL */ '
        {
            posts(first: 10) {
                data {
                    id
                }
            }
        }
        ')->assertJson([
            'data' => [
                'posts' => [
                    'data' => [
                        [
                            'id' => '1',
                        ],
                        [
                            'id' => '2',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testSpecifyCustomBuilderForScoutBuilderWithScoutDirective(): void
    {
        $this->setUpScoutEngine();

        /** @var \Mockery\MockInterface&\Laravel\Scout\Builder $builder */
        $builder = $this->partialMock(\Laravel\Scout\Builder::class);
        $builder->model = new Post();
        app()->bind(\Laravel\Scout\Builder::class, function () use ($builder) {
            return $builder;
        });

        /** @var \Tests\Utils\Models\Post $postA */
        $postA = factory(Post::class)->create([
            'title' => 'great title',
            'task_id' => 1,
        ]);
        factory(Post::class)->create([
            'title' => 'Really great title',
            'task_id' => 2,
        ]);
        factory(Post::class)->create([
            'title' => 'bad title',
            'task_id' => 3,
        ]);

        $this->engine->shouldReceive('map')
            ->andReturn(
                new EloquentCollection([$postA])
            )
            ->once();

        $this->engine->shouldReceive('paginate')
            ->with(
                Mockery::any(),
                Mockery::any(),
                Mockery::not('page')
            )
            ->andReturn(new EloquentCollection([$postA]))
            ->once();

        $this->schema = /** @lang GraphQL */ <<<GRAPHQL
        type Post {
            id: ID!
        }

        type Query {
            posts(
                task: ID! @eq(key: "task_id")
            ): [Post!]!
                @paginate(builder: "{$this->qualifyTestResolver('builderForScoutBuilder')}")
        }
GRAPHQL;

        $this->graphQL(/** @lang GraphQL */ '
        {
            posts(first: 10, task: 1) {
                data {
                    id
                }
            }
        }
        ')->assertJson([
            'data' => [
                'posts' => [
                    'data' => [
                        [
                            'id' => '1',
                        ],
                    ],
                ],
            ],
        ]);

        // Ensure `@eq` directive has been applied on scout builder instance
        $builder->shouldHaveReceived('where')
            ->with(
                'task_id',
                '1'
            );
    }

    public function testPaginateWithScopes(): void
    {
        $namedUser = factory(User::class)->make();
        assert($namedUser instanceof User);
        $namedUser->name = 'A named user';
        $namedUser->save();

        $unnamedUser = factory(User::class)->make();
        assert($unnamedUser instanceof User);
        $unnamedUser->name = null;
        $unnamedUser->save();

        $this->schema = /** @lang GraphQL */ '
        type User {
            id: String!
        }

        type Query {
            users: [User!]! @paginate(scopes: ["named"])
        }
        ';

        $this->graphQL(/** @lang GraphQL */ '
        {
            users(first: 5) {
                paginatorInfo {
                    count
                    total
                    currentPage
                }
                data {
                    id
                }
            }
        }
        ')->assertExactJson([
            'data' => [
                'users' => [
                    'paginatorInfo' => [
                        'count' => 1,
                        'total' => 1,
                        'currentPage' => 1,
                    ],
                    'data' => [
                        [
                            'id' => "$namedUser->id",
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function builder(): Builder
    {
        return User::orderBy('id', 'DESC');
    }

    public function builderForRelation(User $parent): Relation
    {
        return $parent->posts()->orderBy('id', 'DESC');
    }

    public function builderForScoutBuilder(): \Laravel\Scout\Builder
    {
        return Post::search('great title');
    }

    public function testCreateQueryPaginatorsWithDifferentPages(): void
    {
        $users = factory(User::class, 3)->create();

        $firstUser = $users->first();
        assert($firstUser instanceof User);

        $posts = factory(Post::class, 3)->make();
        foreach ($posts as $post) {
            assert($post instanceof Post);
            $post->user()->associate($firstUser);
            $post->save();
        }

        $firstPost = $posts->first();
        assert($firstPost instanceof Post);

        foreach (factory(Comment::class, 3)->make() as $comment) {
            assert($comment instanceof Comment);
            $comment->post()->associate($firstPost);
            $comment->save();
        }

        $this->schema = /** @lang GraphQL */ '
        type User {
            posts: [Post!]! @paginate
        }

        type Post {
            comments: [Comment!]! @paginate
        }

        type Comment {
            id: ID!
        }

        type Query {
            users: [User!]! @paginate
        }
        ';

        $this->graphQL(/** @lang GraphQL */ '
        {
            users(first: 2, page: 1) {
                paginatorInfo {
                    count
                    total
                    currentPage
                }
                data {
                    posts(first: 2, page: 2) {
                        paginatorInfo {
                            count
                            total
                            currentPage
                        }
                        data {
                            comments(first: 1, page: 3) {
                                paginatorInfo {
                                    count
                                    total
                                    currentPage
                                }
                            }
                        }
                    }
                }
            }
        }
        ')->assertJson([
            'data' => [
                'users' => [
                    'paginatorInfo' => [
                        'currentPage' => 1,
                    ],
                    'data' => [
                        [
                            'posts' => [
                                'paginatorInfo' => [
                                    'currentPage' => 2,
                                ],
                                'data' => [
                                    [
                                        'comments' => [
                                            'paginatorInfo' => [
                                                'currentPage' => 3,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testCreateQueryConnections(): void
    {
        factory(User::class, 3)->create();

        $this->schema = /** @lang GraphQL */ '
        type User {
            id: ID!
        }

        type Query {
            users: [User!]! @paginate(type: CONNECTION)
        }
        ';

        $this->graphQL(/** @lang GraphQL */ '
        {
            users(first: 2) {
                pageInfo {
                    hasNextPage
                }
                edges {
                    node {
                        id
                    }
                }
            }
        }
        ')->assertJson([
            'data' => [
                'users' => [
                    'pageInfo' => [
                        'hasNextPage' => true,
                    ],
                ],
            ],
        ])->assertJsonCount(2, 'data.users.edges');
    }

    public function testQueriesConnectionWithNoData(): void
    {
        $this->schema = /** @lang GraphQL */ '
        type User {
            id: ID!
        }

        type Query {
            users: [User!]! @paginate(type: CONNECTION)
        }
        ';

        $this->graphQL(/** @lang GraphQL */ '
        {
            users(first: 5) {
                pageInfo {
                    count
                    currentPage
                    endCursor
                    hasNextPage
                    hasPreviousPage
                    lastPage
                    startCursor
                    total
                }
                edges {
                    node {
                        id
                    }
                }
            }
        }
        ')->assertJson([
            'data' => [
                'users' => [
                    'pageInfo' => [
                        'count' => 0,
                        'currentPage' => 1,
                        'endCursor' => null,
                        'hasNextPage' => false,
                        'hasPreviousPage' => false,
                        'lastPage' => 1,
                        'startCursor' => null,
                        'total' => 0,
                    ],
                ],
            ],
        ])->assertJsonCount(0, 'data.users.edges');
    }

    public function testQueriesPaginationWithNoData(): void
    {
        $this->schema = /** @lang GraphQL */ '
        type User {
            id: ID!
        }

        type Query {
            users: [User!]! @paginate
        }
        ';

        $this->graphQL(/** @lang GraphQL */ '
        {
            users(first: 5) {
                paginatorInfo {
                    count
                    currentPage
                    firstItem
                    hasMorePages
                    lastItem
                    lastPage
                    perPage
                    total
                }
                data {
                    id
                }
            }
        }
        ')->assertJson([
            'data' => [
                'users' => [
                    'paginatorInfo' => [
                        'count' => 0,
                        'currentPage' => 1,
                        'firstItem' => null,
                        'hasMorePages' => false,
                        'lastItem' => null,
                        'lastPage' => 1,
                        'perPage' => 5,
                        'total' => 0,
                    ],
                ],
            ],
        ])->assertJsonCount(0, 'data.users.data');
    }

    public function testQueriesPaginationWithoutPaginatorInfo(): void
    {
        $user = factory(User::class)->create();
        assert($user instanceof User);

        $this->schema = /** @lang GraphQL */ '
        type User {
            id: ID!
        }

        type Query {
            users: [User!]! @paginate
        }
        ';

        $this->assertQueryCountMatches(1, function () use ($user): void {
            $this->graphQL(/** @lang GraphQL */ '
            {
                users(first: 1) {
                    data {
                        id
                    }
                }
            }
            ')->assertJson([
                'data' => [
                    'users' => [
                        'data' => [
                            [
                                'id' => $user->id,
                            ],
                        ],
                    ],
                ],
            ])->assertJsonCount(1, 'data.users.data');
        });
    }

    public function testQueriesConnectionWithoutPageInfo(): void
    {
        $user = factory(User::class)->create();
        assert($user instanceof User);

        $this->schema = /** @lang GraphQL */ '
        type User {
            id: ID!
        }

        type Query {
            users: [User!]! @paginate(type: CONNECTION)
        }
        ';

        $this->assertQueryCountMatches(1, function () use ($user): void {
            $this->graphQL(/** @lang GraphQL */ '
            {
                users(first: 1) {
                    edges {
                        node {
                            id
                        }
                    }
                }
            }
            ')->assertJson([
                'data' => [
                    'users' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => $user->id,
                                ],
                            ],
                        ],
                    ],
                ],
            ])->assertJsonCount(1, 'data.users.edges');
        });
    }

    public function testPaginatesWhenDefinedInTypeExtension(): void
    {
        factory(User::class, 2)->create();

        $this->schema .= /** @lang GraphQL */ '
        type User {
            id: ID!
        }

        extend type Query {
            users: [User!]! @paginate(model: "User")
        }
        ';

        $this->graphQL(/** @lang GraphQL */ '
        {
            users(first: 1) {
                data {
                    id
                }
            }
        }
        ')->assertJsonCount(1, 'data.users.data');
    }

    public function testDefaultPaginationCount(): void
    {
        factory(User::class, 3)->create();

        $this->schema = /** @lang GraphQL */ '
        type User {
            id: ID!
        }

        type Query {
            users: [User!]! @paginate(defaultCount: 2)
        }
        ';

        $this->graphQL(/** @lang GraphQL */ '
        {
            users {
                paginatorInfo {
                    count
                    total
                    currentPage
                }
                data {
                    id
                }
            }
        }
        ')->assertJson([
            'data' => [
                'users' => [
                    'paginatorInfo' => [
                        'count' => 2,
                    ],
                ],
            ],
        ])->assertJsonCount(2, 'data.users.data');
    }

    public function testDoesNotRequireDefaultCountArgIfDefinedInConfig(): void
    {
        factory(User::class, 3)->create();

        $defaultCount = 2;
        config(['lighthouse.pagination.default_count' => $defaultCount]);

        $this->schema = /** @lang GraphQL */ '
        type User {
            id: ID!
            name: String!
        }

        type Query {
            users: [User!] @paginate
        }
        ';

        $this->graphQL(/** @lang GraphQL */ '
        {
            users {
                data {
                    id
                }
            }
        }
        ')->assertJsonCount($defaultCount, 'data.users.data');
    }

    public function testQueriesSimplePagination(): void
    {
        config(['lighthouse.pagination.default_count' => 10]);
        factory(User::class, 3)->create();

        $this->schema = /** @lang GraphQL */ '
        type User {
            id: ID!
            name: String!
        }

        type Query {
            usersPaginated: [User!] @paginate(type: PAGINATOR)
            usersSimplePaginated: [User!] @paginate(type: SIMPLE)
        }
        ';

        // "paginate" fires 2 queries: One for data, one for counting.
        $this->assertQueryCountMatches(2, function (): void {
            $this->graphQL(/** @lang GraphQL */ '
            {
                usersPaginated {
                    paginatorInfo {
                        total
                    }
                    data {
                        id
                    }
                }
            }
            ')->assertJsonCount(3, 'data.usersPaginated.data');
        });

        // "simplePaginate" only fires one query for the data.
        $this->assertQueryCountMatches(1, function (): void {
            $this->graphQL(/** @lang GraphQL */ '
            {
                usersSimplePaginated {
                    data {
                        id
                    }
                }
            }
            ')->assertJsonCount(3, 'data.usersSimplePaginated.data');
        });
    }

    public function testGetSimplePaginationAttributes(): void
    {
        config(['lighthouse.pagination.default_count' => 10]);
        factory(User::class, 3)->create();

        $this->schema = /** @lang GraphQL */ '
        type User {
            id: ID!
            name: String!
        }

        type Query {
            users: [User!] @paginate(type: SIMPLE)
        }
        ';

        $this->graphQL(/** @lang GraphQL */ '
        {
            users {
                paginatorInfo {
                    count
                    currentPage
                    firstItem
                    lastItem
                    perPage
                }
            }
        }
        ')->assertJson([
            'data' => [
                'users' => [
                    'paginatorInfo' => [
                        'count' => 3,
                        'currentPage' => 1,
                        'firstItem' => 1,
                        'lastItem' => 3,
                        'perPage' => 10,
                    ],
                ],
            ],
        ]);
    }
}
