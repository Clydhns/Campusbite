<?php
function cbStoreOrderingSql(string $alias): string {
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) throw new LogicException('Invalid store alias.');
    return "CASE WHEN public.cb_store_accepts_orders({$alias}.is_open,{$alias}.opens_at,{$alias}.closes_at,clock_timestamp()) THEN 1 ELSE 0 END";
}
function cbStoreAcceptingOrders(array $store, ?DateTimeImmutable $now = null): bool {
    if ((int) ($store['is_open'] ?? 0) !== 1) return false;
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('Asia/Manila'));
    $time = $now->format('H:i:s');
    $opens = $store['opens_at'] ?? '06:00:00';
    $closes = $store['closes_at'] ?? '18:00:00';
    return $opens < $closes ? $time >= $opens && $time < $closes
        : ($opens !== $closes && ($time >= $opens || $time < $closes));
}
function cbStoreHoursLabel(array $store, bool $withZone = true): string {
    $zone = new DateTimeZone('Asia/Manila');
    $opens = new DateTimeImmutable($store['opens_at'] ?? '06:00:00', $zone);
    $closes = new DateTimeImmutable($store['closes_at'] ?? '18:00:00', $zone);
    return $opens->format('g:i A') . ' - ' . $closes->format('g:i A') . ' daily' . ($withZone ? ' (Philippine time)' : '');
}
function cbStoreStatusLabel(array $store): string {
    return (cbStoreAcceptingOrders($store) ? 'Open now' : 'Closed now') . ' | ' . cbStoreHoursLabel($store);
}
// Student kitchen cards: a green "Open now" or red "Closed" pill, then the daily hours.
function cbStoreStatusPill(array $store): string {
    $open = cbStoreAcceptingOrders($store);
    return '<span class="cb-open-pill" data-open="' . ($open ? 'true' : 'false') . '">' . ($open ? 'Open now' : 'Closed') . '</span>'
        . '<span class="cb-kitchen-hours">' . htmlspecialchars(cbStoreHoursLabel($store, false), ENT_QUOTES, 'UTF-8') . '</span>';
}
function cbValidateStoreHours($opens, $closes): array {
    foreach ([$opens, $closes] as $value) {
        if (!is_string($value) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value)) throw new RuntimeException('Enter valid opening and closing times.');
    }
    if ($opens === $closes) throw new RuntimeException('Opening and closing times must be different.');
    return [$opens, $closes];
}
