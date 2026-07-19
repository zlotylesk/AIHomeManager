<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Notifications\NotificationsRequestParser;
use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
use App\Module\Notifications\Application\Command\RegisterPushSubscription;
use App\Module\Notifications\Application\Command\RemovePushSubscription;
use App\Module\Notifications\Application\Command\SetChannelPreference;
use App\Module\Notifications\Application\Command\SetQuietHours;
use App\Module\Notifications\Application\Command\ToggleNotificationType;
use App\Module\Notifications\Application\DTO\NotificationDTO;
use App\Module\Notifications\Application\DTO\NotificationPreferenceDTO;
use App\Module\Notifications\Application\Query\GetNotificationHistory;
use App\Module\Notifications\Application\Query\GetNotificationPreferences;
use InvalidArgumentException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/notifications')]
final class NotificationsController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        private readonly NormalizerInterface $normalizer,
        private readonly NotificationsRequestParser $parser,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $vapidPublicKey,
    ) {
    }

    #[Route('/preferences', methods: ['GET'])]
    #[OA\Get(
        summary: 'Read notification preferences',
        description: 'Returns every notification type with its enabled flag, enabled channels and quiet-hours window. Types the user never configured are returned with their default state rather than omitted.',
        tags: ['Notifications'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The preference panel, one entry per notification type.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: NotificationPreferenceDTO::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function preferences(): JsonResponse
    {
        /** @var NotificationPreferenceDTO[] $preferences */
        $preferences = $this->queryBus->ask(new GetNotificationPreferences());

        return new JsonResponse($this->normalizer->normalize($preferences));
    }

    #[Route('/preferences/{type}/enabled', methods: ['PATCH'])]
    #[OA\Patch(
        summary: 'Turn a notification type on or off',
        tags: ['Notifications'],
        parameters: [new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['enabled'], properties: [new OA\Property(property: 'enabled', type: 'boolean')]),
        ),
        responses: [
            new OA\Response(response: 204, description: 'The type was updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function toggleType(string $type, Request $request): Response
    {
        $payload = $this->parser->decode($request);
        $enabled = $this->parser->parseBool($payload, 'enabled');

        return $this->dispatchWrite(new ToggleNotificationType($type, $enabled));
    }

    #[Route('/preferences/{type}/channels/{channel}', methods: ['PATCH'])]
    #[OA\Patch(
        summary: 'Turn one channel on or off for a notification type',
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'channel', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['email', 'push'])),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['enabled'], properties: [new OA\Property(property: 'enabled', type: 'boolean')]),
        ),
        responses: [
            new OA\Response(response: 204, description: 'The channel was updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function setChannel(string $type, string $channel, Request $request): Response
    {
        $payload = $this->parser->decode($request);
        $enabled = $this->parser->parseBool($payload, 'enabled');

        return $this->dispatchWrite(new SetChannelPreference($type, $channel, $enabled));
    }

    #[Route('/preferences/{type}/quiet-hours', methods: ['PUT'])]
    #[OA\Put(
        summary: 'Set or clear the quiet-hours window for a notification type',
        description: 'Send both "from" and "to" as "HH:MM" to set the window, or both as null to clear it. Sending only one end is rejected — a silently dropped half-range would persist as "no quiet hours".',
        tags: ['Notifications'],
        parameters: [new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'from', type: 'string', example: '22:00', nullable: true),
                new OA\Property(property: 'to', type: 'string', example: '07:00', nullable: true),
            ]),
        ),
        responses: [
            new OA\Response(response: 204, description: 'The quiet window was set or cleared.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function setQuietHours(string $type, Request $request): Response
    {
        $payload = $this->parser->decode($request);

        return $this->dispatchWrite(new SetQuietHours(
            $type,
            $this->parser->parseOptionalTime($payload, 'from'),
            $this->parser->parseOptionalTime($payload, 'to'),
        ));
    }

    #[Route('/push/key', methods: ['GET'])]
    #[OA\Get(
        summary: 'Read the VAPID public key',
        description: 'The browser needs this key to create a push subscription. Only the public half is ever served; the private key never leaves the server.',
        tags: ['Notifications'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The VAPID public key, or an empty string when push is not configured.',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'publicKey', type: 'string')]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function pushKey(): JsonResponse
    {
        return new JsonResponse(['publicKey' => $this->vapidPublicKey]);
    }

    #[Route('/push/subscriptions', methods: ['POST'])]
    #[OA\Post(
        summary: 'Register a browser push subscription',
        description: 'Idempotent by endpoint: re-sending the subscription a browser already registered is a no-op.',
        tags: ['Notifications'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['endpoint', 'publicKey', 'authToken'],
                properties: [
                    new OA\Property(property: 'endpoint', type: 'string'),
                    new OA\Property(property: 'publicKey', type: 'string', description: 'The subscription\'s p256dh key.'),
                    new OA\Property(property: 'authToken', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 204, description: 'The subscription is registered.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function registerPushSubscription(Request $request): Response
    {
        $payload = $this->parser->decode($request);

        return $this->dispatchWrite(new RegisterPushSubscription(
            $this->parser->parseString($payload, 'endpoint'),
            $this->parser->parseString($payload, 'publicKey'),
            $this->parser->parseString($payload, 'authToken'),
        ));
    }

    #[Route('/push/subscriptions', methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Remove a browser push subscription',
        description: 'Removing an endpoint that is already gone succeeds — the caller\'s intent is satisfied either way.',
        tags: ['Notifications'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['endpoint'], properties: [new OA\Property(property: 'endpoint', type: 'string')]),
        ),
        responses: [
            new OA\Response(response: 204, description: 'The browser will no longer receive push notifications.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function removePushSubscription(Request $request): Response
    {
        $payload = $this->parser->decode($request);

        return $this->dispatchWrite(new RemovePushSubscription($this->parser->parseString($payload, 'endpoint')));
    }

    #[Route('/history', methods: ['GET'])]
    #[OA\Get(
        summary: 'List recent notifications',
        tags: ['Notifications'],
        parameters: [new OA\Parameter(
            name: 'limit',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'integer', default: 20, maximum: 100, minimum: 1),
        )],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The most recent notifications, newest first.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: NotificationDTO::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function history(Request $request): JsonResponse
    {
        $limit = $this->parser->parseLimit($request, 20, GetNotificationHistory::MAX_LIMIT);

        /** @var NotificationDTO[] $notifications */
        $notifications = $this->queryBus->ask(new GetNotificationHistory($limit));

        return new JsonResponse($this->normalizer->normalize($notifications));
    }

    /**
     * Domain validation lives in the handlers; the controller only translates a
     * rejected value into the 422 the rest of the API returns.
     */
    private function dispatchWrite(object $command): Response
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $failure) {
            $previous = $failure->getPrevious();

            if ($previous instanceof InvalidArgumentException) {
                return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            throw $failure;
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
