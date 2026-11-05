<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Tests;

use UniOne\Client\Transport\TransportInterface;

/**
 * Transport of the tests: records the requests and answers with the queued replies (then {"status":"success"});
 * a queued \Throwable is thrown. Nothing is sent over the network.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{url: string, headers: array<string, string>, body: string, timeout: float}> */
    public $requests = [];

    /** @var list<array{status: int, body: string}|\Throwable> */
    private $replies;

    /**
     * @param list<array{status: int, body: string}|\Throwable> $replies
     */
    public function __construct(array $replies = [])
    {
        $this->replies = $replies;
    }

    /**
     * A reply with a JSON body.
     *
     * @param array<string, mixed> $data
     *
     * @return array{status: int, body: string}
     */
    public static function json(int $status, array $data): array
    {
        return ['status' => $status, 'body' => (string)\json_encode($data)];
    }

    /**
     * @param array{status: int, body: string}|\Throwable $reply
     */
    public function add($reply): self
    {
        $this->replies[] = $reply;

        return $this;
    }

    public function post(string $url, array $headers, string $body, float $timeout): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
        $reply = \array_shift($this->replies);
        if ($reply instanceof \Throwable) {
            throw $reply;
        }

        return $reply ?? self::json(200, ['status' => 'success']);
    }
}
