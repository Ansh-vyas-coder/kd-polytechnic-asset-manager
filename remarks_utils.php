<?php

function remarks_build_asset_summary(array $items): string
{
    $parts = [];

    foreach ($items as $item) {
        $asset_no = trim((string)($item['asset_no'] ?? ''));
        $quantity = (int)($item['quantity'] ?? 1);

        if ($asset_no === '') {
            $asset_no = 'Item #' . (int)($item['id'] ?? 0);
        }

        $parts[] = $asset_no . ' (Qty: ' . max($quantity, 1) . ')';
    }

    return implode(', ', $parts);
}

function remarks_split_blocks(string $remarks): array
{
    $remarks = trim(str_replace(["\r\n", "\r"], "\n", $remarks));
    if ($remarks === '') {
        return [];
    }

    return preg_split('/\n{2,}/', $remarks) ?: [];
}

function remarks_upsert_block(string $existing_remarks, string $header, string $body): string
{
    $existing_remarks = trim(str_replace(["\r\n", "\r"], "\n", $existing_remarks));
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    $header = trim($header);

    if ($header === '' || $body === '') {
        return $existing_remarks;
    }

    $new_block = $header . "\n" . $body;
    if ($existing_remarks === '') {
        return $new_block;
    }

    $blocks = remarks_split_blocks($existing_remarks);
    foreach ($blocks as $index => $block) {
        $block_header = trim(strtok($block, "\n") ?: '');
        if ($block_header === $header) {
            $blocks[$index] = $new_block;
            return trim(implode("\n\n", $blocks));
        }
    }

    $blocks[] = $new_block;
    return trim(implode("\n\n", $blocks));
}

function remarks_build_transfer_body(array $items, string $target, string $action_label = 'transferred to'): string
{
    $target = trim($target);
    $summary = remarks_build_asset_summary($items);
    $total_quantity = 0;

    foreach ($items as $item) {
        $total_quantity += max((int)($item['quantity'] ?? 1), 1);
    }

    $date_text = date('d/m/Y');
    $count = count($items);
    $count_text = $count === 1 ? '1 selected asset' : $count . ' selected assets';
    $verb = $count === 1 ? 'was' : 'were';

    return "On {$date_text}, {$count_text} with total quantity {$total_quantity} ({$summary}) {$verb} {$action_label} {$target}.";
}

function remarks_build_lab_change_body(array $items, string $lab_name): string
{
    return remarks_build_transfer_body($items, $lab_name, 'reassigned to');
}

function remarks_dedupe_structured_blocks(string $remarks): string
{
    $blocks = remarks_split_blocks($remarks);
    if (empty($blocks)) {
        return trim($remarks);
    }

    $seen_headers = [];
    $output_blocks = [];

    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }

        $header = trim(strtok($block, "\n") ?: '');
        if ($header === 'Transfer Note' || $header === 'Lab Reassign Note') {
            if (isset($seen_headers[$header])) {
                continue;
            }
            $seen_headers[$header] = true;
        }

        $output_blocks[] = $block;
    }

    return trim(implode("\n\n", $output_blocks));
}
