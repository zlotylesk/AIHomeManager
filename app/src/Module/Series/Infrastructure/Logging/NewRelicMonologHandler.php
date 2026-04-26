<?php

declare(strict_types=1);

namespace App\Module\Series\Infrastructure\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

final class NewRelicMonologHandler extends AbstractProcessingHandler
{
    private bool $extensionAvailable;

    public function __construct(
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        ?bool $extensionAvailable = null,
    ) {
        parent::__construct($level, $bubble);
        $this->extensionAvailable = $extensionAvailable ?? extension_loaded('newrelic');

        if (!$this->extensionAvailable) {
            error_log('NewRelicMonologHandler: newrelic extension not loaded, handler disabled');
        }
    }

    protected function write(LogRecord $record): void
    {
        if (!$this->extensionAvailable) {
            return;
        }

        if ($record->level->value >= Level::Error->value && function_exists('newrelic_notice_error')) {
            newrelic_notice_error($record->message);
        }

        if (function_exists('newrelic_add_custom_parameter')) {
            newrelic_add_custom_parameter('log_channel', $record->channel);
            newrelic_add_custom_parameter('log_level', $record->level->getName());
            newrelic_add_custom_parameter('log_message', $record->message);
        }
    }
}
