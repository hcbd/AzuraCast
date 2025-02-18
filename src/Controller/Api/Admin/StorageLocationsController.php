<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity;
use App\Http\Response;
use App\Http\ServerRequest;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @OA\Get(path="/admin/storage_locations",
 *   operationId="getStorageLocations",
 *   tags={"Administration: Storage Locations"},
 *   description="List all current storage locations in the system.",
 *   @OA\Response(response=200, description="Success",
 *     @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Api_Admin_StorageLocation"))
 *   ),
 *   @OA\Response(response=403, description="Access denied"),
 *   security={{"api_key": {}}},
 * )
 *
 * @OA\Post(path="/admin/storage_locations",
 *   operationId="addStorageLocation",
 *   tags={"Administration: Storage Locations"},
 *   description="Create a new storage location.",
 *   @OA\RequestBody(
 *     @OA\JsonContent(ref="#/components/schemas/Api_Admin_StorageLocation")
 *   ),
 *   @OA\Response(response=200, description="Success",
 *     @OA\JsonContent(ref="#/components/schemas/Api_Admin_StorageLocation")
 *   ),
 *   @OA\Response(response=403, description="Access denied"),
 *   security={{"api_key": {}}},
 * )
 *
 * @OA\Get(path="/admin/storage_location/{id}",
 *   operationId="getStorageLocation",
 *   tags={"Administration: Storage Locations"},
 *   description="Retrieve details for a single storage location.",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     description="User ID",
 *     required=true,
 *     @OA\Schema(type="integer", format="int64")
 *   ),
 *   @OA\Response(response=200, description="Success",
 *     @OA\JsonContent(ref="#/components/schemas/Api_Admin_StorageLocation")
 *   ),
 *   @OA\Response(response=403, description="Access denied"),
 *   security={{"api_key": {}}},
 * )
 *
 * @OA\Put(path="/admin/storage_location/{id}",
 *   operationId="editStorageLocation",
 *   tags={"Administration: Storage Locations"},
 *   description="Update details of a single storage location.",
 *   @OA\RequestBody(
 *     @OA\JsonContent(ref="#/components/schemas/Api_Admin_StorageLocation")
 *   ),
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     description="Storage Location ID",
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
 * @OA\Delete(path="/admin/storage_location/{id}",
 *   operationId="deleteStorageLocation",
 *   tags={"Administration: Storage Locations"},
 *   description="Delete a single storage location.",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     description="Storage Location ID",
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
 * @extends AbstractAdminApiCrudController<Entity\StorageLocation>
 */
class StorageLocationsController extends AbstractAdminApiCrudController
{
    protected string $entityClass = Entity\StorageLocation::class;
    protected string $resourceRouteName = 'api:admin:storage_location';

    public function __construct(
        protected Entity\Repository\StorageLocationRepository $storageLocationRepo,
        ReloadableEntityManagerInterface $em,
        Serializer $serializer,
        ValidatorInterface $validator
    ) {
        parent::__construct($em, $serializer, $validator);
    }

    public function listAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $qb = $this->em->createQueryBuilder();

        $qb->select('sl')
            ->from(Entity\StorageLocation::class, 'sl');

        $type = $request->getQueryParam('type');
        if (!empty($type)) {
            $qb->andWhere('sl.type = :type')
                ->setParameter('type', $type);
        }

        $query = $qb->getQuery();

        return $this->listPaginatedFromQuery($request, $response, $query);
    }

    /** @inheritDoc */
    protected function viewRecord(object $record, ServerRequest $request): object
    {
        /** @var Entity\StorageLocation $record */
        $original = parent::viewRecord($record, $request);

        $return = new Entity\Api\Admin\StorageLocation();
        $return->fromParentObject($original);

        $return->storageQuotaBytes = (string)($record->getStorageQuotaBytes() ?? '');
        $return->storageUsedBytes = (string)$record->getStorageUsedBytes();
        $return->storageUsedPercent = $record->getStorageUsePercentage();
        $return->storageAvailable = $record->getStorageAvailable();
        $return->storageAvailableBytes = (string)($record->getStorageAvailableBytes() ?? '');
        $return->isFull = $record->isStorageFull();

        $return->uri = $record->getUri();

        $stationsRaw = $this->storageLocationRepo->getStationsUsingLocation($record);
        $stations = [];
        foreach ($stationsRaw as $station) {
            $stations[] = $station->getName();
        }
        $return->stations = $stations;

        return $return;
    }

    protected function deleteRecord(object $record): void
    {
        if (!($record instanceof Entity\StorageLocation)) {
            throw new InvalidArgumentException(sprintf('Record must be an instance of %s.', $this->entityClass));
        }

        $stations = $this->storageLocationRepo->getStationsUsingLocation($record);

        if (0 !== count($stations)) {
            $stationNames = [];
            foreach ($stations as $station) {
                $stationNames[] = $station->getName();
            }

            throw new RuntimeException('This storage location has stations associated with it, and cannot be '
                . ' deleted until these stations are updated: ' . implode(', ', $stationNames));
        }

        parent::deleteRecord($record);
    }
}
