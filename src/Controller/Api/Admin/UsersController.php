<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Controller\Api\Traits\CanSortResults;
use App\Controller\Frontend\Account\MasqueradeAction;
use App\Entity;
use App\Http\Response;
use App\Http\ServerRequest;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * @OA\Get(path="/admin/users",
 *   operationId="getUsers",
 *   tags={"Administration: Users"},
 *   description="List all current users in the system.",
 *   @OA\Response(response=200, description="Success",
 *     @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/User"))
 *   ),
 *   @OA\Response(response=403, description="Access denied"),
 *   security={{"api_key": {}}},
 * )
 *
 * @OA\Post(path="/admin/users",
 *   operationId="addUser",
 *   tags={"Administration: Users"},
 *   description="Create a new user.",
 *   @OA\RequestBody(
 *     @OA\JsonContent(ref="#/components/schemas/User")
 *   ),
 *   @OA\Response(response=200, description="Success",
 *     @OA\JsonContent(ref="#/components/schemas/User")
 *   ),
 *   @OA\Response(response=403, description="Access denied"),
 *   security={{"api_key": {}}},
 * )
 *
 * @OA\Get(path="/admin/user/{id}",
 *   operationId="getUser",
 *   tags={"Administration: Users"},
 *   description="Retrieve details for a single current user.",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     description="User ID",
 *     required=true,
 *     @OA\Schema(type="integer", format="int64")
 *   ),
 *   @OA\Response(response=200, description="Success",
 *     @OA\JsonContent(ref="#/components/schemas/User")
 *   ),
 *   @OA\Response(response=403, description="Access denied"),
 *   security={{"api_key": {}}},
 * )
 *
 * @OA\Put(path="/admin/user/{id}",
 *   operationId="editUser",
 *   tags={"Administration: Users"},
 *   description="Update details of a single user.",
 *   @OA\RequestBody(
 *     @OA\JsonContent(ref="#/components/schemas/User")
 *   ),
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     description="User ID",
 *     required=true,
 *     @OA\Schema(type="integer", format="int64")
 *   ),
 *   @OA\Response(response=200, description="Success",
 *     @OA\JsonContent(ref="#/components/schemas/Api_Status")
 *   ),
 *   @OA\Response(response=403, description="Access denied"),
 *   security={{"api_key": {}}},
 * )
 *
 * @OA\Delete(path="/admin/user/{id}",
 *   operationId="deleteUser",
 *   tags={"Administration: Users"},
 *   description="Delete a single user.",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     description="User ID",
 *     required=true,
 *     @OA\Schema(type="integer", format="int64")
 *   ),
 *   @OA\Response(response=200, description="Success",
 *     @OA\JsonContent(ref="#/components/schemas/Api_Status")
 *   ),
 *   @OA\Response(response=403, description="Access denied"),
 *   security={{"api_key": {}}},
 * )
 *
 * @extends AbstractAdminApiCrudController<Entity\User>
 */
class UsersController extends AbstractAdminApiCrudController
{
    use CanSortResults;

    protected string $entityClass = Entity\User::class;
    protected string $resourceRouteName = 'api:admin:user';

    /**
     * @param ServerRequest $request
     * @param Response $response
     */
    public function listAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(Entity\User::class, 'e');

        $qb = $this->sortQueryBuilder(
            $request,
            $qb,
            [
                'name' => 'e.name',
            ],
            'e.name'
        );

        $searchPhrase = trim($request->getParam('searchPhrase', ''));
        if (!empty($searchPhrase)) {
            $qb->andWhere('(e.name LIKE :name OR e.email LIKE :name)')
                ->setParameter('name', '%' . $searchPhrase . '%');
        }

        return $this->listPaginatedFromQuery($request, $response, $qb->getQuery());
    }

    protected function viewRecord(object $record, ServerRequest $request): mixed
    {
        if (!($record instanceof Entity\User)) {
            throw new InvalidArgumentException(sprintf('Record must be an instance of %s.', $this->entityClass));
        }

        $return = $this->toArray($record);

        $isInternal = ('true' === $request->getParam('internal', 'false'));
        $router = $request->getRouter();
        $csrf = $request->getCsrf();
        $currentUser = $request->getUser();

        $return['is_me'] = $currentUser->getIdRequired() === $record->getIdRequired();

        $return['links'] = [
            'self'       => (string)$router->fromHere(
                route_name: $this->resourceRouteName,
                route_params: ['id' => $record->getIdRequired()],
                absolute: !$isInternal
            ),
            'masquerade' => (string)$router->fromHere(
                route_name: 'account:masquerade',
                route_params: [
                    'id'   => $record->getIdRequired(),
                    'csrf' => $csrf->generate(MasqueradeAction::CSRF_NAMESPACE),
                ],
                absolute: !$isInternal
            ),
        ];

        return $return;
    }

    public function editAction(ServerRequest $request, Response $response, mixed $id): ResponseInterface
    {
        $record = $this->getRecord($id);

        if (null === $record) {
            return $response->withStatus(404)
                ->withJson(Entity\Api\Error::notFound());
        }

        $currentUser = $request->getUser();
        if ($record->getId() === $currentUser->getId()) {
            return $response->withStatus(403)
                ->withJson(new Entity\Api\Error(403, __('You cannot modify yourself.')));
        }

        $this->editRecord((array)$request->getParsedBody(), $record);

        return $response->withJson(Entity\Api\Status::updated());
    }

    public function deleteAction(ServerRequest $request, Response $response, mixed $id): ResponseInterface
    {
        $record = $this->getRecord($id);

        if (null === $record) {
            return $response->withStatus(404)
                ->withJson(Entity\Api\Error::notFound());
        }

        $currentUser = $request->getUser();
        if ($record->getId() === $currentUser->getId()) {
            return $response->withStatus(403)
                ->withJson(new Entity\Api\Error(403, __('You cannot remove yourself.')));
        }

        $this->deleteRecord($record);

        return $response->withJson(Entity\Api\Status::deleted());
    }
}
