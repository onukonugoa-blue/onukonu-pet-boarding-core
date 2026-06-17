<?php
/**
 * OPB_Woocommerce_Adapter
 *
 * OPSMAIL WooCommerce Producer — Architecture Stub (v2.9.0)
 *
 * PURPOSE
 * -------
 * This class defines the integration contract between WooCommerce events
 * and the OPSMAIL Operational Intelligence Repository (opb_opsmail_queue).
 *
 * WooCommerce is already structured. No AI or mail parsing is required.
 * Events are normalised by this adapter and inserted directly into the queue.
 *
 * ARCHITECTURE
 * ------------
 *
 *   WooCommerce Hook
 *       ↓
 *   OPB_Woocommerce_Adapter::on_*()    ← normaliser
 *       ↓
 *   OPB_Opsmail::push_event()          ← writer (source_system = WOOCOMMERCE)
 *       ↓
 *   opb_opsmail_queue                  ← canonical repository
 *
 * EVENT TAXONOMY (planned)
 * ------------------------
 *   ORDER.PLACED          — woocommerce_checkout_order_created
 *   ORDER.PAID            — woocommerce_payment_complete
 *   ORDER.CANCELLED       — woocommerce_order_status_cancelled
 *   ORDER.REFUNDED        — woocommerce_order_refunded
 *   SUBSCRIPTION.CREATED  — woocommerce_subscription_created (if WC Subscriptions active)
 *   SUBSCRIPTION.RENEWED  — woocommerce_subscription_renewal_payment_complete
 *   SUBSCRIPTION.CANCELLED — woocommerce_subscription_status_cancelled
 *
 * NORMALISED PAYLOAD SHAPE (example — ORDER.PAID)
 * ------------------------------------------------
 *   order_id       INT
 *   order_key      STRING
 *   customer_id    INT
 *   customer_name  STRING
 *   customer_email STRING
 *   total          FLOAT
 *   currency       STRING
 *   items          ARRAY   [{product_id, name, qty, subtotal}]
 *   payment_method STRING
 *   billing_city   STRING
 *   created_at     STRING  (ISO 8601)
 *
 * ZERO REGRESSION GUARANTEE
 * -------------------------
 *   - This class MUST NOT intercept or modify any existing WooCommerce workflow.
 *   - All hook callbacks MUST be additive only (add_action, never remove_action).
 *   - All methods MUST be wrapped in try/catch(\Throwable).
 *   - OPSMAIL failures MUST NOT block or delay order processing.
 *
 * MAILBOX PROCESSOR CONTRACT (future)
 * ------------------------------------
 *   WooCommerce OPSMAIL events carry an X-Ops-Event-UUID header.
 *   A future mailbox processor will:
 *     1. Receive the OPSMAIL email at the inbox.
 *     2. Extract X-Ops-Event-UUID.
 *     3. Check opb_opsmail_queue — already processed?
 *        YES → Ignore (idempotency guarantee).
 *        NO  → Forward to Telegram Operations Group.
 *             → Set telegram_status = SENT in opb_opsmail_queue.
 *
 * IMPLEMENTATION STATUS
 * ---------------------
 *   Hooks:        NOT REGISTERED — dormant until explicitly activated.
 *   Polling:      NOT IMPLEMENTED.
 *   WooCommerce:  NOT MODIFIED in any way.
 *
 * To activate, uncomment register_hooks() and call it from OPB_Core::init()
 * AFTER all WooCommerce compatibility checks pass.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OPB_Woocommerce_Adapter {

    /**
     * Register WooCommerce action hooks.
     *
     * NOT CALLED in v2.9.0. Reserved for future activation.
     * Caller must verify WooCommerce is active before calling this method.
     */
    public static function register_hooks(): void {
        // add_action( 'woocommerce_checkout_order_created',          [ self::class, 'on_order_placed'   ], 99, 1 );
        // add_action( 'woocommerce_payment_complete',                 [ self::class, 'on_order_paid'     ], 99, 1 );
        // add_action( 'woocommerce_order_status_cancelled',           [ self::class, 'on_order_cancelled'], 99, 1 );
        // add_action( 'woocommerce_order_refunded',                   [ self::class, 'on_order_refunded' ], 99, 2 );
    }

    // ── Planned normaliser stubs ──────────────────────────────────────────────

    /**
     * ORDER.PLACED — fires after checkout order is created.
     * Maps WC_Order to normalised OPSMAIL payload.
     */
    public static function on_order_placed( /* WC_Order */ $order ): void {
        // TODO: implement when activating WooCommerce integration.
        // Normalise $order → push via OPB_Opsmail with source_system = SOURCE_WOOCOMMERCE.
    }

    /**
     * ORDER.PAID — fires after successful payment.
     */
    public static function on_order_paid( int $order_id ): void {
        // TODO: implement when activating WooCommerce integration.
    }

    /**
     * ORDER.CANCELLED — fires when order status changes to cancelled.
     */
    public static function on_order_cancelled( int $order_id ): void {
        // TODO: implement when activating WooCommerce integration.
    }

    /**
     * ORDER.REFUNDED — fires after a refund is processed.
     */
    public static function on_order_refunded( int $order_id, int $refund_id ): void {
        // TODO: implement when activating WooCommerce integration.
    }
}
