<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use InvalidArgumentException;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\WooCommerce\Contracts\OrderGenerationPolicy;

/**
 * Produces an internal payment confirmation, and only after payment is confirmed.
 *
 * This policy replaces the status-driven ConfigurableStatusPolicy, which could
 * emit `invoice` and `proforma`. Those are fiscal document types and must not be
 * generated automatically while invoices are issued manually in Fakturownia —
 * two automatic issuers of officially-typed documents is the failure mode this
 * module exists to avoid.
 *
 * "Paid" requires both a paid-class order status and a payment date recorded by
 * the gateway. Offline gateways (cash on delivery, bank transfer) never set a
 * payment date, so they are unpaid by default and are only treated as paid when
 * an operator explicitly enrols that gateway through COD_POLICY_STATUS_ONLY.
 */
final class PaidOrderPolicy implements OrderGenerationPolicy
{
    /** Offline gateways never satisfy the paid test. Safe default. */
    public const COD_POLICY_NEVER = 'never';

    /** Enrolled offline gateways count as paid on a paid-class status alone. */
    public const COD_POLICY_STATUS_ONLY = 'status_only';

    public const DEFAULT_PAID_STATUSES = ['processing', 'completed'];

    /** @var string[] */
    private $paidStatuses;
    /** @var string */
    private $codPolicy;
    /** @var string[] */
    private $offlineMethods;
    /** @var string */
    private $name;
    /** @var int */
    private $version;

    /**
     * @param string[] $paidStatuses
     * @param string[] $offlineMethods
     */
    public function __construct(
        array $paidStatuses = self::DEFAULT_PAID_STATUSES,
        string $codPolicy = self::COD_POLICY_NEVER,
        array $offlineMethods = [],
        string $name = 'payment-confirmation',
        int $version = 1
    ) {
        if (trim($name) === '' || $version < 1) {
            throw new InvalidArgumentException('Policy name and positive version are required.');
        }
        if (!in_array($codPolicy, [self::COD_POLICY_NEVER, self::COD_POLICY_STATUS_ONLY], true)) {
            throw new InvalidArgumentException('Unsupported cash-on-delivery policy.');
        }
        $this->paidStatuses = array_values(array_unique(array_map('strval', $paidStatuses)));
        if ($this->paidStatuses === []) {
            throw new InvalidArgumentException('At least one payment confirmation status is required.');
        }
        $this->codPolicy = $codPolicy;
        $this->offlineMethods = array_values(array_unique(array_map(
            static function ($method): string {
                return strtolower(trim((string) $method));
            },
            $offlineMethods
        )));
        $this->name = trim($name);
        $this->version = $version;
    }

    public function documentTypeFor(OrderData $order): ?DocumentType
    {
        return $this->isPaid($order)
            ? DocumentType::fromString(DocumentType::PAYMENT_CONFIRMATION)
            : null;
    }

    public function isPaid(OrderData $order): bool
    {
        if (!in_array($order->status, $this->paidStatuses, true)) {
            return false;
        }
        if ($order->paidAt !== '') {
            return true;
        }

        return $this->codPolicy === self::COD_POLICY_STATUS_ONLY
            && in_array(strtolower($order->paymentMethod), $this->offlineMethods, true);
    }

    /**
     * Why a given order did or did not qualify. Recorded on the document and in
     * the audit log so the decision is reconstructable later.
     *
     * @return array<string, scalar|null>
     */
    public function decision(OrderData $order): array
    {
        $isPaid = $this->isPaid($order);
        return [
            'policy' => $this->name,
            'policy_version' => $this->version,
            'payment_method' => $order->paymentMethod,
            'payment_confirmed' => $isPaid ? 'yes' : 'no',
            'payment_status' => $isPaid
                ? ($order->paidAt !== '' ? 'gateway_confirmed' : 'offline_status_confirmed')
                : 'not_qualified',
            'payment_badge' => $isPaid ? 'paid' : 'unpaid',
            'payment_notice' => $isPaid
                ? 'OPŁACONE — płatność potwierdzona'
                : 'NIEOPŁACONE — płatność niepotwierdzona',
            'cod_policy' => $this->codPolicy,
        ];
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): int
    {
        return $this->version;
    }
}
