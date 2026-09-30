<?php

/**
 * This file is part of Milpa AI Gateway — the dual-provider LLM client and agentic
 * tool-use runtime for the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/ai-gateway
 */

declare(strict_types=1);

namespace Milpa\AiGateway;

/**
 * The ONE reading of a provider's base URL — every door this gateway knocks on is this root plus its own path.
 *
 * There were three readings and they disagreed (greenhouse evidence/1069 §C1). {@see ProviderReach} and
 * {@see ProviderWindow} trimmed a trailing `/v1`; {@see LlmService} did not. So with
 * `http://…:11438/v1` — the spelling every OpenAI guide shows — the catalogue question asked `/v1/models` and
 * answered «1 model(s) served», while the turn asked `/v1/v1/chat/completions` and died on a 404. Two judges of
 * the same endpoint, one saying yes and the other failing: the panel said a thing the turn could not do.
 *
 * BOTH SPELLINGS SERVE (greenhouse decisions/0536). A base URL with or without its `/v1` names the same server:
 * refusing one of them would make a person unlearn the guide they copied from, and the only thing it would
 * protect is a provider whose chat lives under `/v1/v1/…`, which none does.
 */
final class ProviderEndpoint
{
    /**
     * The root every path is appended to: trimmed, without a trailing slash and without a trailing `/v1`.
     *
     * Only a WHOLE trailing `/v1` segment is dropped: `…/api/v1` keeps `/api`, `…/v1beta` stays as it is.
     */
    public static function root(string $baseUrl): string
    {
        $root = rtrim(trim($baseUrl), '/');

        return preg_match('~/v1$~i', $root) === 1 ? substr($root, 0, -3) : $root;
    }

    /**
     * An endpoint as a person may read it — on the panel, in the ledger: without the credentials a base URL
     * can carry (`http://user:secret@host`), and without a query or fragment (where a key sometimes travels).
     * A string that is not a URL is returned as it came.
     */
    public static function shown(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
    }
}
