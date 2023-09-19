<?php

namespace Enigma;

use Exception;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class GoogleChatHandler extends AbstractProcessingHandler
{
    /**
     * Additional logs closure.
     *
     * @var \Closure|null
     */
    public static \Closure|null $additionalLogs = null;

    /**
     * Writes the record down to the log of the implementing handler.
     *
     * @param LogRecord $record
     *
     * @throws \Exception
     */
    protected function write(LogRecord $record): void
    {
        foreach ($this->getWebhookUrl() as $url) {
            Http::post($url, $this->getRequestBody($record));
        }
    }

    /**
     * Get the webhook url.
     *
     * @return array
     *
     * @throws \Exception
     */
    protected function getWebhookUrl(): array
    {
        $url = config('logging.channels.google-chat.url');
        if (!$url) {
            throw new Exception('Google chat webhook url is not configured.');
        }

        if (is_array($url)) {
            return $url;
        }

        return array_map(function ($each) {
            return trim($each);
        }, explode(',', $url));
    }

    /**
     * Get the request body content.
     *
     * @param LogRecord $record
     * @return array
     */
    protected function getRequestBody(LogRecord $record): array
    {
        return [
            'text' => substr($this->getNotifiableText($record->level->value ?? '') . $record->formatted, 0, 4096),
            'cardsV2' => [
                [
                    'cardId' => 'info-card-id',
                    'card' => [
                        'header' => [
                            'title' => "{$record->level->name}: {$record->message}",
                            'subtitle' => config('app.name'),
                        ],
                        'sections' => [
                            'header' => 'Details',
                            'collapsible' => true,
                            'uncollapsibleWidgetsCount' => 3,
                            'widgets' => [
                                $this->cardWidget(ucwords(config('app.env') ?: 'NA') . ' [Env]', 'BOOKMARK'),
                                $this->cardWidget($this->getLevelContent($record), 'TICKET'),
                                $this->cardWidget($record->datetime, 'CLOCK'),
                                $this->cardWidget(request()->url(), 'BUS'),
                                ...$this->getCustomLogs(),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get the card content.
     *
     * @param LogRecord $record
     * @return string
     */
    protected function getLevelContent(LogRecord $record): string
    {
        $color = [
            Level::Emergency->value => '#ff1100',
            Level::Alert->value => '#ff1100',
            Level::Critical->value => '#ff1100',
            Level::Error->value => '#ff1100',
            Level::Warning->value => '#ffc400',
            Level::Notice->value => '#00aeff',
            Level::Info->value => '#48d62f',
            Level::Debug->value => '#000000',
        ][$record->level->value] ?? '#ff1100';

        return "<font color='{$color}'>{$record->level->name}</font>";
    }

    /**
     * Get the text string for notifying the configured user id.
     *
     * @param $level
     * @return string
     */
    protected function getNotifiableText($level): string
    {
        $levelBasedUserIds = [
            Level::Emergency->value => config('logging.channels.google-chat.notify_users.emergency'),
            Level::Alert->value => config('logging.channels.google-chat.notify_users.alert'),
            Level::Critical->value => config('logging.channels.google-chat.notify_users.critical'),
            Level::Error->value => config('logging.channels.google-chat.notify_users.error'),
            Level::Warning->value => config('logging.channels.google-chat.notify_users.warning'),
            Level::Notice->value => config('logging.channels.google-chat.notify_users.notice'),
            Level::Info->value => config('logging.channels.google-chat.notify_users.info'),
            Level::Debug->value => config('logging.channels.google-chat.notify_users.debug'),
        ][$level] ?? '';

        $levelBasedUserIds = trim($levelBasedUserIds);
        if (($userIds = config('logging.channels.google-chat.notify_users.default')) && $levelBasedUserIds) {
            $levelBasedUserIds = ",$levelBasedUserIds";
        }

        return $this->constructNotifiableText(trim($userIds) . $levelBasedUserIds);
    }

    /**
     * Get the notifiable text for the given userIds String.
     *
     * @param $userIds
     * @return string
     */
    protected function constructNotifiableText($userIds): string
    {
        if (!$userIds) {
            return '';
        }

        $allUsers = '';
        $otherIds = implode(array_map(function ($userId) use (&$allUsers) {
            if (strtolower($userId) === 'all') {
                $allUsers = '<users/all> ';
                return '';
            }

            return "<users/$userId> ";
        }, array_unique(
                explode(',', $userIds))
        ));

        return $allUsers . $otherIds;
    }

    /**
     * Card widget content.
     *
     * @return array[]
     */
    public function cardWidget(string $text, string $icon): array
    {
        return [
            'decoratedText' => [
                'startIcon' => [
                    'knownIcon' => $icon,
                ],
                'text' => $text,
            ],
        ];
    }

    /**
     * Get the custom logs.
     *
     * @return array
     * @throws Exception
     */
    public function getCustomLogs(): array
    {
        $additionalLogs = GoogleChatHandler::$additionalLogs;
        if (!$additionalLogs) {
            return [];
        }

        $additionalLogs = $additionalLogs(request());
        if (!is_array($additionalLogs)) {
            throw new Exception('Data returned from the additional Log must be an array.');
        }

        $logs = [];
        foreach ($additionalLogs as $key => $value) {
            if ($value && !is_string($value)) {
                try {
                    $value = json_encode($value);
                } catch (\Throwable $throwable) {
                    throw new Exception("Additional log key-value should be a string for key[{$key}]. For logging objects, json or array, please stringify by doing json encode or serialize on the value.", 0, $throwable);
                }
            }

            if (!is_numeric($key)) {
                $key = ucwords(str_replace('_', ' ', $key));
                $value = "<b>{$key}:</b> $value";
            }
            $logs[] = $this->cardWidget($value, 'CONFIRMATION_NUMBER_ICON');
        }

        return $logs;
    }
}
