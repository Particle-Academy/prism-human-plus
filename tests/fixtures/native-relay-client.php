<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Prism\HumanPlus\Data\Participant;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Data\SurfaceInvitation;
use Prism\HumanPlus\Transport\SsePostRelayTransport;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$origin = $argv[1];
$port = (int) parse_url($origin, PHP_URL_PORT);
$attachment = new SurfaceAttachment('surface_x', 'session:one', new SurfaceInvitation($origin, 'b3session', str_repeat('T', 32), 'board:fixture', 'Fixture', true), new Participant('agent:b3', 'Fixture', '#fff'), 'b3_client');
$transport = new SsePostRelayTransport(new Client, ['127.0.0.1'], timeoutSeconds: 3, allowedRelayPorts: [$port], allowUnverifiedEgress: true, useNativeCurl: true);

try {
    $reply = $transport->exchange($attachment, ['jsonrpc' => '2.0', 'id' => 'probe', 'method' => 'tools/call', 'params' => (object) []]);
    echo json_encode(['outcome' => 'returned', 'reply' => $reply], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['outcome' => basename(str_replace('\\', '/', $error::class)), 'errorMessage' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
