<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

/** Has this user taken delivery of this product at least once? */
function has_delivered_purchase(int $userId, int $productId): bool
{
    // order_items -> sub_orders (the per-seller split, which is where status
    // lives) -> orders (which is where the customer lives).
    $stmt = db()->prepare(
        'SELECT 1
           FROM order_items oi
           JOIN sub_orders so ON so.id = oi.sub_order_id
           JOIN orders o      ON o.id  = so.order_id
          WHERE o.user_id = ?
            AND oi.product_id = ?
            AND so.status = \'delivered\'
          LIMIT 1'
    );
    $stmt->execute([$userId, $productId]);
    return (bool) $stmt->fetchColumn();
}

function existing_review_id(int $userId, int $productId): ?int
{
    $stmt = db()->prepare('SELECT id FROM reviews WHERE user_id = ? AND product_id = ? LIMIT 1');
    $stmt->execute([$userId, $productId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

function review_permission(?array $user, int $productId): array
{
    $deny = static fn(string $reason, string $message): array => [
        'can_review' => false,
        'reason'     => $reason,
        'message'    => $message,
    ];

    if ($user === null) {
        return $deny('signed_out', 'Sign in to write a review');
    }
    if ($user['role'] !== 'customer') {
        // Sellers and admins buying through their own platform is not a
        // flow this project supports, so keep reviews to shoppers.
        return $deny('not_customer', 'Only customer accounts can write reviews');
    }
    if (existing_review_id((int) $user['id'], $productId) !== null) {
        return $deny('already_reviewed', 'You have already reviewed this product');
    }
    if (!has_delivered_purchase((int) $user['id'], $productId)) {
        return $deny('not_delivered', 'You can review this once your order has been delivered');
    }

    return ['can_review' => true, 'reason' => 'ok', 'message' => 'Write a review'];
}
