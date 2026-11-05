<?php

namespace App\Routing;

use App\Models\Email;
use App\Models\Provider;
use App\Models\RoutingRule;
use Illuminate\Support\Collection;

class EmailRouter
{
    /**
     * Pick the routing rule for an email: an explicitly forced rule (X-Route),
     * else the first active rule whose conditions all match, else the default.
     */
    public function ruleFor(Email $email): ?RoutingRule
    {
        $rules = RoutingRule::with('providers')
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($email->forced_route) {
            $forced = $rules->first(fn (RoutingRule $r) => strcasecmp($r->name, $email->forced_route) === 0 || (string) $r->id === $email->forced_route);
            if ($forced) {
                return $forced;
            }
        }

        return $rules->first(fn (RoutingRule $r) => ! $r->is_default && $this->matches($r, $email))
            ?? $rules->first(fn (RoutingRule $r) => $r->is_default);
    }

    public function matches(RoutingRule $rule, Email $email): bool
    {
        $conditions = array_filter($rule->conditions ?? [], fn ($v) => $v !== null && $v !== '');
        if ($conditions === []) {
            return true;
        }

        foreach ($conditions as $key => $expected) {
            $ok = match ($key) {
                'from_domain' => $this->domainMatches($email->fromDomain(), $expected),
                'to_domain' => collect($email->recipientDomains())->contains(fn ($d) => $this->domainMatches($d, $expected)),
                'tag' => $this->tagMatches($email->tags ?? [], (string) $expected),
                'api_key_id' => (int) $email->api_key_id === (int) $expected,
                default => true,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ordered list of providers to try for an email.
     *
     * Failover: by pivot priority. Weighted: one provider drawn by weight goes
     * first, the rest follow by priority as fallbacks. Providers that are
     * disabled or cooling down after repeated failures are skipped. When no
     * rule exists, every active provider is used in failover order.
     *
     * @return Collection<int, Provider>
     */
    public function candidates(Email $email, ?RoutingRule $rule = null): Collection
    {
        $rule ??= $this->ruleFor($email);

        if ($rule) {
            $providers = $rule->providers->filter(fn (Provider $p) => $p->isAvailable())->values();
            $priority = fn (Provider $p) => [$p->pivot->priority, $p->id];
            $weight = fn (Provider $p) => (int) $p->pivot->weight;
            $strategy = $rule->strategy;
        } else {
            $providers = Provider::where('is_active', true)->get()->filter(fn (Provider $p) => $p->isAvailable())->values();
            $priority = fn (Provider $p) => [$p->priority, $p->id];
            $weight = fn (Provider $p) => (int) $p->weight;
            $strategy = 'failover';
        }

        $ordered = $providers->sortBy($priority)->values();

        if ($strategy !== 'weighted' || $ordered->count() < 2) {
            return $ordered;
        }

        $first = $this->pickWeighted($ordered, $weight);
        if ($first === null) {
            return $ordered;
        }

        return $ordered->reject(fn (Provider $p) => $p->is($first))->prepend($first)->values();
    }

    /**
     * @param  Collection<int, Provider>  $providers
     */
    public function pickWeighted(Collection $providers, callable $weight): ?Provider
    {
        $total = $providers->sum(fn ($p) => max(0, $weight($p)));
        if ($total <= 0) {
            return null;
        }

        $roll = random_int(1, $total);
        foreach ($providers as $p) {
            $roll -= max(0, $weight($p));
            if ($roll <= 0) {
                return $p;
            }
        }

        return $providers->last();
    }

    /** "example.com" matches example.com; "*.example.com" also matches subdomains. */
    private function domainMatches(string $domain, string $pattern): bool
    {
        foreach (preg_split('/[\s,]+/', strtolower(trim($pattern))) as $p) {
            if ($p === '') {
                continue;
            }
            if (str_starts_with($p, '*.')) {
                $base = substr($p, 2);
                if ($domain === $base || str_ends_with($domain, '.'.$base)) {
                    return true;
                }
            } elseif ($domain === $p) {
                return true;
            }
        }

        return false;
    }

    /** "newsletter" matches a tag named newsletter; "category:promo" matches name and value. */
    private function tagMatches(array $tags, string $expected): bool
    {
        [$name, $value] = array_pad(explode(':', $expected, 2), 2, null);

        foreach ($tags as $t) {
            if (strcasecmp($t['name'] ?? '', $name) === 0 && ($value === null || (string) ($t['value'] ?? '') === $value)) {
                return true;
            }
        }

        return false;
    }
}
