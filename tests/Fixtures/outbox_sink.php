<?php

$stateFile = getenv('OUTBOX_TEST_SINK_STATE');
$mode = getenv('OUTBOX_TEST_SINK_MODE');
$event = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! is_array($event) || ! isset($event['event_id'])) {
    http_response_code(400);
    exit;
}

$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : null;
$state ??= ['requests' => [], 'processed' => []];
$state['requests'][] = $event;
$firstForEvent = ! isset($state['processed'][$event['event_id']]);
$state['processed'][$event['event_id']] = true;
file_put_contents($stateFile, json_encode($state), LOCK_EX);

// Simulate a lost acknowledgment after the consumer has processed the event.
http_response_code($mode === 'lost_ack' && $firstForEvent ? 503 : 202);
header('Content-Type: application/json');
echo json_encode(['accepted' => true]);
