<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Google\Client as GoogleClient;
use Google\Service\Calendar;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Relaticle\EmailIntegration\Data\GoogleServiceAvailability;
use Relaticle\EmailIntegration\Enums\GoogleDisabledService;

final readonly class ProbeGoogleServices
{
    private const int TIMEOUT_SECONDS = 5;

    public function execute(string $accessToken, bool $includeCalendar): GoogleServiceAvailability
    {
        $client = $this->client($accessToken);

        return new GoogleServiceAvailability(
            mail: $this->isAvailable(
                fn (): mixed => (new Gmail($client))->users->getProfile('me'),
                GoogleDisabledService::Mail,
            ),
            calendar: $includeCalendar && $this->isAvailable(
                fn (): mixed => (new Calendar($client))->calendarList->listCalendarList(['maxResults' => 1]),
                GoogleDisabledService::Calendar,
            ),
        );
    }

    private function client(string $accessToken): GoogleClient
    {
        $client = new GoogleClient;

        $client->setHttpClient($this->httpClient());
        $client->setAccessToken(['access_token' => $accessToken]);

        return $client;
    }

    private function httpClient(): ClientInterface
    {
        $stack = Http::buildHandlerStack();

        $stack->push(static fn (callable $handler): callable => static fn (RequestInterface $request, array $options): PromiseInterface => $handler($request, [
            ...$options,
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::TIMEOUT_SECONDS,
        ]));

        return Http::createClient($stack);
    }

    private function isAvailable(callable $call, GoogleDisabledService $service): bool
    {
        try {
            $call();
        } catch (GoogleServiceException|TransferException $exception) {
            return GoogleDisabledService::fromException($exception) !== $service;
        }

        return true;
    }
}
