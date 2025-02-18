<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations;

use App\Entity;
use App\Exception;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Paginator;
use App\Utilities;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * @OA\Get(path="/station/{station_id}/requests",
 *   operationId="getRequestableSongs",
 *   tags={"Stations: Song Requests"},
 *   description="Return a list of requestable songs.",
 *   @OA\Parameter(ref="#/components/parameters/station_id_required"),
 *   @OA\Response(
 *     response=200,
 *     description="Success",
 *     @OA\Schema(
 *       type="array",
 *       @OA\Items(ref="#/components/schemas/Api_StationRequest")
 *     )
 *   ),
 *   @OA\Response(response=404, description="Station not found"),
 *   @OA\Response(response=403, description="Station does not support requests")
 * )
 *
 * @OA\Post(path="/station/{station_id}/request/{request_id}",
 *   operationId="submitSongRequest",
 *   tags={"Stations: Song Requests"},
 *   description="Submit a song request.",
 *   @OA\Parameter(ref="#/components/parameters/station_id_required"),
 *   @OA\Parameter(
 *     name="request_id",
 *     description="The requestable song ID",
 *     in="path",
 *     required=true,
 *     @OA\Schema(
 *         type="string"
 *     )
 *   ),
 *   @OA\Response(response=200, description="Success"),
 *   @OA\Response(response=404, description="Station not found"),
 *   @OA\Response(response=403, description="Station does not support requests")
 * )
 */
class RequestsController
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected Entity\Repository\StationRequestRepository $requestRepo,
        protected Entity\ApiGenerator\SongApiGenerator $songApiGenerator
    ) {
    }

    public function listAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();

        // Verify that the station supports requests.
        $ba = $request->getStationBackend();
        if (!$ba->supportsRequests() || !$station->getEnableRequests()) {
            return $response->withStatus(403)
                ->withJson(new Entity\Api\Error(403, __('This station does not accept requests currently.')));
        }

        $qb = $this->em->createQueryBuilder();

        $qb->select('sm, spm, sp')
            ->from(Entity\StationMedia::class, 'sm')
            ->leftJoin('sm.playlists', 'spm')
            ->leftJoin('spm.playlist', 'sp')
            ->where('sm.storage_location = :storageLocation')
            ->andWhere('sp.id IS NOT NULL')
            ->andWhere('sp.station = :station')
            ->andWhere('sp.is_enabled = 1')
            ->andWhere('sp.include_in_requests = 1')
            ->setParameter('storageLocation', $station->getMediaStorageLocation())
            ->setParameter('station', $station);

        $params = $request->getQueryParams();

        if (!empty($params['sort'])) {
            $sortDirection = (($params['sortOrder'] ?? 'ASC') === 'ASC') ? 'ASC' : 'DESC';

            match ($params['sort']) {
                'name', 'song_title' => $qb->addOrderBy('sm.title', $sortDirection),
                'song_artist' => $qb->addOrderBy('sm.artist', $sortDirection),
                'song_album' => $qb->addOrderBy('sm.album', $sortDirection),
                'song_genre' => $qb->addOrderBy('sm.genre', $sortDirection),
                default => null,
            };
        } else {
            $qb->orderBy('sm.artist', 'ASC')
                ->addOrderBy('sm.title', 'ASC');
        }

        $search_phrase = trim($params['searchPhrase'] ?? '');
        if (!empty($search_phrase)) {
            $qb->andWhere('(sm.title LIKE :query OR sm.artist LIKE :query OR sm.album LIKE :query)')
                ->setParameter('query', '%' . $search_phrase . '%');
        }

        $paginator = Paginator::fromQueryBuilder($qb, $request);

        $is_bootgrid = $paginator->isFromBootgrid();
        $router = $request->getRouter();

        $paginator->setPostprocessor(
            function (Entity\StationMedia $media_row) use ($station, $is_bootgrid, $router) {
                $row = new Entity\Api\StationRequest();
                $row->song = ($this->songApiGenerator)($media_row, $station);
                $row->request_id = $media_row->getUniqueId();
                $row->request_url = (string)$router->named(
                    'api:requests:submit',
                    [
                        'station_id' => $station->getId(),
                        'media_id' => $media_row->getUniqueId(),
                    ]
                );

                $row->resolveUrls($router->getBaseUrl());

                if ($is_bootgrid) {
                    return Utilities\Arrays::flattenArray($row, '_');
                }

                return $row;
            }
        );

        return $paginator->write($response);
    }

    public function submitAction(ServerRequest $request, Response $response, string $media_id): ResponseInterface
    {
        $station = $request->getStation();

        // Verify that the station supports requests.
        $ba = $request->getStationBackend();
        if (!$ba->supportsRequests() || !$station->getEnableRequests()) {
            return $response->withStatus(403)
                ->withJson(new Entity\Api\Error(403, __('This station does not accept requests currently.')));
        }

        try {
            $user = $request->getUser();
        } catch (Exception\InvalidRequestAttribute) {
            $user = null;
        }

        $isAuthenticated = ($user instanceof Entity\User);

        try {
            $this->requestRepo->submit(
                $station,
                $media_id,
                $isAuthenticated,
                $request->getIp(),
                $request->getHeaderLine('User-Agent')
            );

            return $response->withJson(Entity\Api\Status::success());
        } catch (Exception $e) {
            return $response->withStatus(400)
                ->withJson(new Entity\Api\Error(400, $e->getMessage()));
        }
    }
}
