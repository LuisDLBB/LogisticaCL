<?php

namespace App\Modules\Operations\Services;

use Illuminate\Support\Collection;

class OperationGuideRoutePlanner
{
    public function expectedLegCount(int $trunkCode, int $agencyCode, bool $hasSecondPost): int
    {
        if ($trunkCode === 4 && $agencyCode >= 8 && $agencyCode <= 21) {
            return $agencyCode <= 13 ? 1 : ($agencyCode <= 17 ? 2 : ($agencyCode <= 20 ? 3 : 4));
        }
        if ($trunkCode === 6 && in_array($agencyCode, [27, 28, 29, 30, 31, 32, 33], true)) {
            return in_array($agencyCode, [31, 32], true) ? 2 : 1;
        }

        return $hasSecondPost ? 3 : 2;
    }

    /** @return array<int, array<string, mixed>> */
    public function legs(object $agency, object $trunk, Collection $postsById, Collection $postsByCode, Collection $agenciesByCode): array
    {
        $origin = $this->point($trunk->name.' · origen', $trunk->origin_address, $trunk->origin_commune);
        $trunkEnd = $this->point($trunk->name.' · destino', $trunk->destination_address, $trunk->destination_commune);
        $destination = $this->point($agency->name, $agency->address, $agency->commune);
        $firstPost = $postsById->get($agency->post_id);
        $secondPost = $agency->second_post_id ? $postsById->get($agency->second_post_id) : null;
        if (! $firstPost || ($agency->second_post_id && ! $secondPost)) {
            return [];
        }

        if (in_array((int) $trunk->trunk_code, [1, 2, 3], true)) {
            $airport = $this->postOrigin($firstPost, $trunkEnd);

            return [
                $this->leg(1, 'troncal', $origin, $trunkEnd, 'trunk', $trunk->id, 'air:airport', 1),
                $this->leg(2, 'posta1', $airport, $destination, 'post', $firstPost->id, 'air:agency:'.$agency->agency_code, (int) $agency->agency_code),
            ];
        }

        if ((int) $trunk->trunk_code === 4 && (int) $agency->agency_code >= 8 && (int) $agency->agency_code <= 21) {
            $chillan = $this->agencyPoint($agenciesByCode, 13);
            $concepcion = $this->agencyPoint($agenciesByCode, 15);
            $temuco = $this->agencyPoint($agenciesByCode, 17);
            $puertoMontt = $this->agencyPoint($agenciesByCode, 20);
            $toConcepcion = $postsByCode->get(9);
            $toTemuco = $postsByCode->get(10);
            $toPuertoMontt = $postsByCode->get(11);
            $toChonchi = $postsByCode->get(12);
            if (! $chillan || ! $concepcion || ! $temuco || ! $puertoMontt
                || ! $toConcepcion || ! $toTemuco || ! $toPuertoMontt || ! $toChonchi) {
                return [];
            }

            $code = (int) $agency->agency_code;
            if ($code <= 13) {
                return [$this->leg(1, 'troncal', $origin, $destination, 'trunk', $trunk->id, 'south:direct:'.$code, $code - 7)];
            }

            $legs = [$this->leg(1, 'troncal', $origin, $chillan, 'trunk', $trunk->id, 'south:chillan:transfer', 7)];
            if ($code <= 15) {
                $legs[] = $this->leg(2, 'posta1', $chillan, $concepcion, 'post', $toConcepcion->id, 'south:concepcion:'.$code, $code - 13);

                return $legs;
            }

            if ($code <= 17) {
                $legs[] = $this->leg(2, 'posta1', $chillan, $destination, 'post', $toTemuco->id, 'south:temuco:'.$code, $code - 15);

                return $legs;
            }

            $legs[] = $this->leg(2, 'posta1', $chillan, $temuco, 'post', $toTemuco->id, 'south:temuco:transfer', 3);
            if ($code <= 20) {
                $legs[] = $this->leg(3, 'posta2', $temuco, $destination, 'post', $toPuertoMontt->id, 'south:puerto-montt:'.$code, $code - 17);

                return $legs;
            }

            $legs[] = $this->leg(3, 'posta2', $temuco, $puertoMontt, 'post', $toPuertoMontt->id, 'south:puerto-montt:transfer', 4);
            $legs[] = $this->leg(4, 'posta3', $puertoMontt, $destination, 'post', $toChonchi->id, 'south:chonchi', 1);

            return $legs;
        }

        if ((int) $trunk->trunk_code === 6 && in_array((int) $agency->agency_code, [31, 32], true)) {
            $coquimbo = $this->agencyPoint($agenciesByCode, 30);
            $toCopiapo = $postsByCode->get(20);
            if (! $coquimbo || ! $toCopiapo) {
                return [];
            }

            return [
                $this->leg(1, 'troncal', $origin, $coquimbo, 'trunk', $trunk->id, 'north:coquimbo:transfer', 5),
                $this->leg(2, 'posta1', $coquimbo, $destination, 'post', $toCopiapo->id, 'north:agency:'.$agency->agency_code, (int) $agency->agency_code === 32 ? 1 : 2),
            ];
        }

        if ((int) $trunk->trunk_code === 6 && in_array((int) $agency->agency_code, [27, 28, 29, 30, 33], true)) {
            return [$this->leg(1, 'troncal', $origin, $destination, 'trunk', $trunk->id, 'north:direct:'.$agency->agency_code, match ((int) $agency->agency_code) {
                27 => 1,
                28 => 2,
                29 => 3,
                30 => 4,
                33 => 6,
            })];
        }

        $firstOrigin = $this->postOrigin($firstPost, $trunkEnd);
        $legs = [$this->leg(1, 'troncal', $origin, $trunkEnd, 'trunk', $trunk->id, null, 1)];
        if ($secondPost) {
            $legs[] = $this->leg(2, 'posta1', $firstOrigin, $trunkEnd, 'post', $firstPost->id, null, 2);
            $legs[] = $this->leg(3, 'posta2', $this->postOrigin($secondPost, $trunkEnd), $destination, 'post', $secondPost->id, null, 3);
        } else {
            $legs[] = $this->leg(2, 'posta1', $firstOrigin, $destination, 'post', $firstPost->id, null, 2);
        }

        return $legs;
    }

    /** @return array{name: string, address: string, commune: string} */
    private function point(string $name, ?string $address, ?string $commune): array
    {
        return ['name' => $name, 'address' => trim((string) $address), 'commune' => trim((string) $commune)];
    }

    /** @return array{name: string, address: string, commune: string}|null */
    private function agencyPoint(Collection $agenciesByCode, int $code): ?array
    {
        $agency = $agenciesByCode->get($code);
        if (! $agency || blank($agency->address) || OperationAccess::key($agency->address) === 'pendiente') {
            return null;
        }

        return $this->point($agency->name, $agency->address, $agency->commune);
    }

    /** @param  array{name: string, address: string, commune: string}  $fallback
     * @return array{name: string, address: string, commune: string}
     */
    private function postOrigin(object $post, array $fallback): array
    {
        return filled($post->origin_address) && filled($post->origin_commune)
            ? $this->point($post->name.' · origen', $post->origin_address, $post->origin_commune)
            : $fallback;
    }

    /** @param  array{name: string, address: string, commune: string}  $origin
     * @param  array{name: string, address: string, commune: string}  $destination
     * @return array<string, mixed>
     */
    private function leg(int $sequence, string $role, array $origin, array $destination, string $transportKind, int $transportId, ?string $groupCode, int $stopOrder): array
    {
        return compact('sequence', 'role', 'origin', 'destination', 'transportKind', 'transportId', 'groupCode', 'stopOrder');
    }
}
