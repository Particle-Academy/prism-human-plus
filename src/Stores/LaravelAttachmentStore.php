<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Stores;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Encryption\Encrypter;
use Prism\HumanPlus\Contracts\AttachmentStore;
use Prism\HumanPlus\Data\Participant;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Prism\HumanPlus\Enums\AttachmentState;
use Prism\HumanPlus\Exceptions\HumanPlusException;

final readonly class LaravelAttachmentStore implements AttachmentStore
{
    public function __construct(
        private CacheFactory $cache,
        private Encrypter $encrypter,
        private ?string $store = null,
        private string $prefix = 'prism-human-plus:',
        private int $ttlSeconds = 86400,
    ) {}

    public function get(string $id): ?SurfaceAttachment
    {
        $encrypted = $this->cache->store($this->store)->get($this->prefix.$id);
        if (! is_string($encrypted)) {
            return null;
        }
        $json = $this->encrypter->decrypt($encrypted, unserialize: false);
        if (! is_string($json)) {
            throw new HumanPlusException('Stored Human+ attachment is malformed.');
        }
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new HumanPlusException('Stored Human+ attachment is malformed.');
        }
        $invitation = new SurfaceInvitation((string) $value['relay'], (string) $value['session'], (string) $value['token'], (string) $value['surface'], (string) $value['application'], (bool) ($value['allow_insecure_loopback'] ?? false));
        $participant = new Participant((string) $value['participant_id'], (string) $value['participant_name'], (string) $value['participant_color']);

        return new SurfaceAttachment((string) $value['id'], (string) $value['owner'], $invitation, $participant, (string) $value['client'], (int) $value['generation'], AttachmentState::from((string) $value['state']));
    }

    public function put(SurfaceAttachment $attachment, ?int $expectedGeneration = null): void
    {
        if ($expectedGeneration !== null && $this->get($attachment->id)?->generation !== $expectedGeneration) {
            throw new HumanPlusException('Human+ attachment changed while this worker was acting.');
        }
        $value = [
            'id' => $attachment->id, 'owner' => $attachment->owner, 'relay' => $attachment->invitation->relayBaseUrl,
            'session' => $attachment->invitation->sessionId, 'token' => $attachment->invitation->token,
            'allow_insecure_loopback' => $attachment->invitation->allowInsecureLoopback,
            'surface' => $attachment->invitation->surfaceId, 'application' => $attachment->invitation->application,
            'participant_id' => $attachment->participant->id, 'participant_name' => $attachment->participant->name,
            'participant_color' => $attachment->participant->color, 'client' => $attachment->clientId,
            'generation' => $attachment->generation, 'state' => $attachment->state->value,
        ];
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->cache->store($this->store)->put($this->prefix.$attachment->id, $this->encrypter->encrypt($json, serialize: false), $this->ttlSeconds);
    }

    public function lock(string $id, Closure $callback): mixed
    {
        $repository = $this->cache->store($this->store);
        if (! $repository->getStore() instanceof LockProvider) {
            throw new HumanPlusException('Configured Human+ attachment cache does not support atomic locks.');
        }

        return $repository->getStore()->lock($this->prefix.'lock:'.$id, 45)->block(5, $callback);
    }
}
