<?php

namespace ErnestDefoe\StoryChain\Api;

use ErnestDefoe\StoryChain\Story;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

trait Finds
{
    private function id(ServerRequestInterface $request): int
    {
        return (int) Arr::get($request->getQueryParams(), 'id');
    }

    private function story(ServerRequestInterface $request): ?Story
    {
        $id = $this->id($request);

        return $id > 0 ? Story::query()->find($id) : null;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private static function isDuplicate(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? '');

        return $state === '23000' || $state === '23505' || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
