<?php

use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Optional profiling bootstrap: never record command payloads, results or credentials.
$subscriber = new class implements CommandSubscriber
{
    public array $commands = [];

    public array $collections = [];

    public int $indexDefinitions = 0;

    public function commandStarted(CommandStartedEvent $event): void
    {
        $name = $event->getCommandName();
        $this->commands[$name] ??= ['started' => 0, 'succeeded' => 0, 'failed' => 0, 'seconds' => 0];
        $this->commands[$name]['started']++;
        $command = $event->getCommand();
        $collection = $command->{$name} ?? null;
        if (is_string($collection)) {
            $key = $name.':'.$collection;
            $this->collections[$key] = ($this->collections[$key] ?? 0) + 1;
        }
        if ($name === 'createIndexes') {
            $this->indexDefinitions += count($command->indexes ?? []);
        }
    }

    public function commandSucceeded(CommandSucceededEvent $event): void
    {
        $this->commands[$event->getCommandName()]['succeeded']++;
        $this->commands[$event->getCommandName()]['seconds'] += $event->getDurationMicros() / 1e6;
    }

    public function commandFailed(CommandFailedEvent $event): void
    {
        $this->commands[$event->getCommandName()]['failed']++;
        $this->commands[$event->getCommandName()]['seconds'] += $event->getDurationMicros() / 1e6;
    }
};
MongoDB\Driver\Monitoring\addSubscriber($subscriber);
register_shutdown_function(function () use ($subscriber): void {
    if ($path = getenv('INT1BP1_COMMAND_REPORT')) {
        file_put_contents($path, json_encode([
            'commands' => $subscriber->commands, 'collections' => $subscriber->collections,
            'index_definitions' => $subscriber->indexDefinitions,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
});
