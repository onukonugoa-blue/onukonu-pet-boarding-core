<?php
/**
 * Fired during plugin activation.
 * Creates all custom database tables.
 */
class OPB_Activator {

    public static function activate(): void {
        self::create_tables();
        flush_rewrite_rules( false );
    }

    public static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = [];

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_branches (
            id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code          VARCHAR(10)  NOT NULL,
            name          VARCHAR(100) NOT NULL,
            location      VARCHAR(100) NOT NULL,
            address       TEXT,
            phone         VARCHAR(20),
            email         VARCHAR(100),
            is_active     TINYINT(1)   NOT NULL DEFAULT 1,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_branch_code (code)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_clients (
            id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            legacy_id                INT UNSIGNED,
            wp_user_id               BIGINT UNSIGNED,
            home_branch_id           TINYINT UNSIGNED NOT NULL,
            name                     VARCHAR(150) NOT NULL,
            phone                    VARCHAR(25)  NOT NULL,
            email                    VARCHAR(150),
            address                  TEXT,
            local_guardian_name      VARCHAR(150),
            local_guardian_contact   VARCHAR(25),
            status                   ENUM('active','archived') NOT NULL DEFAULT 'active',
            archive_reason           TEXT,
            onboarding_date          DATE,
            tc_accepted              TINYINT(1)   NOT NULL DEFAULT 0,
            wallet_balance           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            outstanding_balance      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            notes                    TEXT,
            created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_phone (phone),
            KEY idx_branch (home_branch_id),
            KEY idx_legacy (legacy_id)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_pets (
            id                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id                  INT UNSIGNED NOT NULL,
            legacy_id                  INT UNSIGNED,
            name                       VARCHAR(100) NOT NULL,
            pet_type                   ENUM('Dog','Cat','Other') NOT NULL,
            breed                      VARCHAR(100),
            gender                     ENUM('Male','Female','Unknown'),
            breed_size                 ENUM('Small','Medium','Large'),
            coat                       VARCHAR(50),
            weight_kg                  DECIMAL(5,2),
            birthday                   DATE,
            microchip_number           VARCHAR(50),
            neutered_or_spayed         TINYINT(1),
            last_heat_month            TINYINT UNSIGNED,
            last_heat_year             SMALLINT UNSIGNED,
            adoption_status            VARCHAR(50),
            social_media_handle        VARCHAR(100),
            consent_photos             TINYINT(1) DEFAULT 0,
            special_occasion           VARCHAR(100),
            special_occasion_date      DATE,
            vaccination_status         ENUM('Vaccinated','Not vaccinated','Unknown') DEFAULT 'Unknown',
            anti_rabies_date           DATE,
            dhppil_date                DATE,
            corona_date                DATE,
            kennel_cough_date          DATE,
            tick_prevention            TINYINT(1) DEFAULT 0,
            last_tick_prevention_date  DATE,
            tick_prevention_method     VARCHAR(100),
            ongoing_medication         TINYINT(1) DEFAULT 0,
            medication_detail          TEXT,
            major_illness_history      TEXT,
            deworming_date             DATE,
            vet_name                   VARCHAR(150),
            vet_contact                VARCHAR(25),
            dietary_preference         VARCHAR(100),
            additional_meals           TEXT,
            preferences_or_allergies   TEXT,
            first_walk_schedule        VARCHAR(100),
            second_walk_schedule       VARCHAR(100),
            third_walk_schedule        VARCHAR(100),
            is_active                  TINYINT(1) NOT NULL DEFAULT 1,
            created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_client (client_id),
            KEY idx_legacy (legacy_id)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_pet_documents (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            pet_id       INT UNSIGNED NOT NULL,
            doc_type     ENUM('photo','vaccination','other') NOT NULL,
            label        VARCHAR(150),
            file_url     TEXT NOT NULL,
            file_mime    VARCHAR(100),
            seq_number   TINYINT UNSIGNED NOT NULL DEFAULT 1,
            uploaded_by  BIGINT UNSIGNED,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_pet (pet_id)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_boarding_services (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id           TINYINT UNSIGNED NOT NULL,
            catalogue_name      VARCHAR(150) NOT NULL,
            boarding_type       ENUM('DAY','OVERNIGHT') NOT NULL,
            pet_type            ENUM('DOG','CAT','ANY') NOT NULL,
            row_type            VARCHAR(50) NOT NULL,
            amount              DECIMAL(10,2),
            discount_type       VARCHAR(50),
            breed_size          ENUM('Small','Medium','Large'),
            kennel_category     VARCHAR(50),
            meal_name           VARCHAR(100),
            meal_type           VARCHAR(50),
            price_type          VARCHAR(50),
            modifies_base_bill  TINYINT(1) DEFAULT 0,
            min_pets            TINYINT UNSIGNED,
            days                SMALLINT UNSIGNED,
            min_age_months      SMALLINT UNSIGNED,
            max_age_months      SMALLINT UNSIGNED,
            breed               VARCHAR(100),
            extra_info          TEXT,
            is_active           TINYINT(1) NOT NULL DEFAULT 1,
            sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_branch_cat (branch_id, catalogue_name)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_addon_services (
            id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id             TINYINT UNSIGNED NOT NULL,
            name                  VARCHAR(100) NOT NULL,
            description           TEXT,
            service_type          ENUM('FLAT','DISTANCE_SLAB') NOT NULL DEFAULT 'FLAT',
            base_amount           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            visibility            ENUM('PUBLIC','PRIVATE') NOT NULL DEFAULT 'PUBLIC',
            applicable_services   TEXT,
            distance_up_to        DECIMAL(8,2),
            distance_slab_amount  DECIMAL(10,2),
            is_active             TINYINT(1) NOT NULL DEFAULT 1,
            sort_order            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_branch (branch_id)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_bookings (
            id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
            legacy_id               INT UNSIGNED,
            branch_id               TINYINT UNSIGNED NOT NULL,
            client_id               INT UNSIGNED NOT NULL,
            booking_date            DATE NOT NULL,
            payment_status          ENUM('Unpaid','Partially paid','Paid','Overpaid','No bill') NOT NULL DEFAULT 'Unpaid',
            total_billing_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            service_types           VARCHAR(100),
            booking_source          VARCHAR(100),
            notes                   TEXT,
            additional_instruction  TEXT,
            created_by              BIGINT UNSIGNED,
            created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_branch (branch_id),
            KEY idx_client (client_id),
            KEY idx_date (booking_date),
            KEY idx_legacy (legacy_id)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_booking_stays (
            id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id            INT UNSIGNED NOT NULL,
            pet_id                INT UNSIGNED NOT NULL,
            boarding_service_id   INT UNSIGNED,
            status                ENUM('Upcoming','Active','Completed','No show') NOT NULL DEFAULT 'Upcoming',
            boarding_type         ENUM('DAY','OVERNIGHT') NOT NULL,
            check_in_date         DATE NOT NULL,
            check_out_date        DATE NOT NULL,
            actual_check_in_at    DATETIME,
            actual_check_out_at   DATETIME,
            check_in_slot         VARCHAR(50),
            check_out_slot        VARCHAR(50),
            weight_at_checkin     DECIMAL(5,2),
            weight_at_checkout    DECIMAL(5,2),
            meal_type             ENUM('BOARDING_MEALS','PARENT_SUPPLIED_MEAL'),
            kennel                VARCHAR(50),
            kennel_id             INT UNSIGNED,
            final_amount          DECIMAL(10,2),
            late_checkout_fees    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            refund_amount         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            refund_reason         TEXT,
            companion_name        VARCHAR(150),
            companion_phone       VARCHAR(25),
            notes                 TEXT,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_booking (booking_id),
            KEY idx_pet (pet_id),
            KEY idx_checkin_date (check_in_date),
            KEY idx_checkout_date (check_out_date),
            KEY idx_status (status)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_booking_addons (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id      INT UNSIGNED NOT NULL,
            addon_id        INT UNSIGNED NOT NULL,
            count           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            distance        DECIMAL(8,2),
            days            SMALLINT UNSIGNED,
            final_amount    DECIMAL(10,2),
            notes           TEXT,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_booking (booking_id)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_invoices (
            id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id                  INT UNSIGNED NOT NULL,
            branch_id                   TINYINT UNSIGNED NOT NULL,
            legacy_invoice_number       VARCHAR(50),
            invoice_type                ENUM('Booking','Manual') NOT NULL DEFAULT 'Booking',
            invoice_date                DATE NOT NULL,
            revenue                     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            base_amount                 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            addon_amount                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount_amount             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            additional_amount           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            additional_discount_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            paid                        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            due                         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_status              ENUM('Unpaid','Partially paid','Paid','Overpaid','No bill') NOT NULL DEFAULT 'Unpaid',
            payment_mode                VARCHAR(50),
            notes                       TEXT,
            doc_token                   VARCHAR(64)  NULL,
            doc_generated_at            DATETIME     NULL,
            doc_generated_by            BIGINT UNSIGNED NULL,
            doc_pdf_path                VARCHAR(500) NULL,
            created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_booking (booking_id),
            KEY idx_branch (branch_id),
            KEY idx_legacy (legacy_invoice_number)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_invoice_line_items (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id      INT UNSIGNED NOT NULL,
            service         VARCHAR(150),
            sku             VARCHAR(100),
            sku_id          VARCHAR(100),
            category        VARCHAR(100),
            sub_category    VARCHAR(100),
            quantity        DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            amount          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            bill_item_name  VARCHAR(150),
            bill_section    ENUM('Base','Add-on','Discount','Additional') NOT NULL DEFAULT 'Base',
            is_return       TINYINT(1) NOT NULL DEFAULT 0,
            breed           VARCHAR(100),
            breed_size      VARCHAR(50),
            coat_length     VARCHAR(50),
            staff_name      VARCHAR(150),
            hsn_sac_code    VARCHAR(20),
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_invoice (invoice_id)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_payments (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id      INT UNSIGNED NOT NULL,
            branch_id       TINYINT UNSIGNED NOT NULL,
            paid_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            amount          DECIMAL(10,2) NOT NULL,
            mode            ENUM('Cash','UPI','Other') NOT NULL DEFAULT 'Cash',
            source          ENUM('Manual','Online') NOT NULL DEFAULT 'Manual',
            transaction_id  VARCHAR(100),
            recorded_by     BIGINT UNSIGNED,
            notes           TEXT,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_invoice (invoice_id),
            KEY idx_branch (branch_id)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_tasks (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id       TINYINT UNSIGNED NOT NULL,
            client_id       INT UNSIGNED,
            title           VARCHAR(250) NOT NULL,
            description     TEXT,
            status          ENUM('Open','In Progress','Done') NOT NULL DEFAULT 'Open',
            priority        ENUM('Low','Medium','High') NOT NULL DEFAULT 'Medium',
            due_date        DATE,
            assignee        VARCHAR(150),
            assigned_by     VARCHAR(150),
            comments        TEXT,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_branch (branch_id),
            KEY idx_client (client_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_kennels (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id    TINYINT UNSIGNED NOT NULL,
            code         VARCHAR(20)  NOT NULL,
            name         VARCHAR(100) NOT NULL,
            status       ENUM('Available','Occupied','Maintenance','Blocked') NOT NULL DEFAULT 'Available',
            notes        TEXT,
            sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_active    TINYINT(1)   NOT NULL DEFAULT 1,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_branch_kennel_code (branch_id, code),
            KEY idx_branch (branch_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_kennel_staff (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kennel_id    INT UNSIGNED NOT NULL,
            wp_user_id   BIGINT UNSIGNED NOT NULL,
            assigned_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_kennel (kennel_id),
            KEY idx_user (wp_user_id)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_expenses (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id       TINYINT UNSIGNED NOT NULL,
            description     VARCHAR(250) NOT NULL,
            expense_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            mode            ENUM('Cash','UPI','Other') NOT NULL DEFAULT 'Cash',
            category        VARCHAR(100),
            amount          DECIMAL(10,2) NOT NULL,
            amount_inc_tax  DECIMAL(10,2),
            recorded_by     BIGINT UNSIGNED,
            notes           TEXT,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_branch (branch_id)
        ) ENGINE=InnoDB $charset;";

        // ── Expense Categories ────────────────────────────────────────────────────
        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_expense_categories (
            id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            name       VARCHAR(100)  NOT NULL,
            is_active  TINYINT(1)    NOT NULL DEFAULT 1,
            sort_order SMALLINT      NOT NULL DEFAULT 0,
            created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_cat_name (name)
        ) ENGINE=InnoDB $charset;";

        // ── Inquiry & Onboarding Pipeline ─────────────────────────────────────────

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_inquiries (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            token               CHAR(64)     NOT NULL,
            branch_id           TINYINT UNSIGNED,
            owner_name          VARCHAR(150) NOT NULL,
            phone               VARCHAR(25)  NOT NULL,
            email               VARCHAR(150),
            pet_name            VARCHAR(100),
            pet_type            VARCHAR(50),
            desired_check_in    DATE,
            desired_check_out   DATE,
            message             TEXT,
            status              ENUM('NEW','CONTACTED','ONBOARDING_SENT','ONBOARDING_COMPLETED','READY_FOR_REVIEW','CONVERTED','REJECTED','ARCHIVED') NOT NULL DEFAULT 'NEW',
            existing_client_id  INT UNSIGNED,
            onboarding_sent_at  DATETIME,
            onboarding_sent_by  BIGINT UNSIGNED,
            delivery_method     ENUM('EMAIL','WHATSAPP','MANUAL'),
            token_expires_at    DATETIME,
            token_send_count    INT UNSIGNED NOT NULL DEFAULT 0,
            converted_client_id INT UNSIGNED,
            converted_at        DATETIME,
            converted_by        BIGINT UNSIGNED,
            ip_address          VARCHAR(45),
            source              VARCHAR(100) NOT NULL DEFAULT 'web_form',
            created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_token (token),
            KEY idx_phone (phone),
            KEY idx_status (status),
            KEY idx_branch (branch_id)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_onboarding_link_log (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            inquiry_id   INT UNSIGNED NOT NULL,
            event_type   ENUM('SENT','OPENED','ROTATED') NOT NULL,
            token_suffix CHAR(8)      NOT NULL,
            actor_id     BIGINT UNSIGNED,
            actor_name   VARCHAR(150),
            notes        VARCHAR(255),
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_inquiry (inquiry_id),
            KEY idx_event (event_type)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_inquiry_notes (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            inquiry_id      INT UNSIGNED NOT NULL,
            note            TEXT         NOT NULL,
            created_by      BIGINT UNSIGNED,
            created_by_name VARCHAR(150),
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_inquiry (inquiry_id)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_onboarding_clients (
            id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            inquiry_id               INT UNSIGNED NOT NULL,
            name                     VARCHAR(150),
            phone                    VARCHAR(25),
            email                    VARCHAR(150),
            address                  TEXT,
            local_guardian_name      VARCHAR(150),
            local_guardian_contact   VARCHAR(25),
            emergency_contact_name   VARCHAR(150),
            emergency_contact_phone  VARCHAR(25),
            notes                    TEXT,
            tc_accepted              TINYINT(1)   NOT NULL DEFAULT 0,
            tc_accepted_at           DATETIME,
            tc_version               VARCHAR(20)  NOT NULL DEFAULT '1.0',
            tc_ip                    VARCHAR(45),
            completed_at             DATETIME,
            created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_inquiry (inquiry_id),
            KEY idx_inquiry (inquiry_id)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_onboarding_pets (
            id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            inquiry_id                INT UNSIGNED NOT NULL,
            onboarding_client_id      INT UNSIGNED,
            name                      VARCHAR(100),
            pet_type                  ENUM('Dog','Cat','Other'),
            breed                     VARCHAR(100),
            gender                    ENUM('Male','Female','Unknown'),
            breed_size                VARCHAR(20),
            coat                      VARCHAR(50),
            weight_kg                 DECIMAL(5,2),
            birthday                  DATE,
            microchip_number          VARCHAR(50),
            neutered_or_spayed        TINYINT(1),
            vaccination_status        ENUM('Vaccinated','Not vaccinated','Unknown') DEFAULT 'Unknown',
            anti_rabies_date          DATE,
            dhppil_date               DATE,
            corona_date               DATE,
            kennel_cough_date         DATE,
            tick_prevention           TINYINT(1) DEFAULT 0,
            last_tick_prevention_date DATE,
            tick_prevention_method    VARCHAR(100),
            ongoing_medication        TINYINT(1) DEFAULT 0,
            medication_detail         TEXT,
            major_illness_history     TEXT,
            deworming_date            DATE,
            vet_name                  VARCHAR(150),
            vet_contact               VARCHAR(25),
            dietary_preference        VARCHAR(100),
            additional_meals          TEXT,
            preferences_or_allergies  TEXT,
            first_walk_schedule       VARCHAR(100),
            second_walk_schedule      VARCHAR(100),
            third_walk_schedule       VARCHAR(100),
            consent_photos            TINYINT(1) DEFAULT 0,
            social_media_handle       VARCHAR(100),
            special_occasion          VARCHAR(100),
            special_occasion_date     DATE,
            additional_notes          TEXT,
            created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_inquiry (inquiry_id)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_onboarding_documents (
            id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            inquiry_id           INT UNSIGNED NOT NULL,
            onboarding_pet_id    INT UNSIGNED,
            doc_type             ENUM('owner_id','vaccination_card','rabies_cert','kennel_cough_cert','medical_report','pet_photo','other') NOT NULL DEFAULT 'other',
            label                VARCHAR(150),
            file_url             TEXT         NOT NULL,
            file_path            TEXT,
            file_mime            VARCHAR(100),
            uploaded_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_inquiry (inquiry_id),
            KEY idx_pet (onboarding_pet_id)
        ) ENGINE=InnoDB $charset;";

        // ── Client Relationship / My Pets ─────────────────────────────────────

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_client_otps (
            id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            client_id      INT UNSIGNED  NOT NULL,
            email          VARCHAR(150)  NOT NULL,
            otp_hash       VARCHAR(255)  NOT NULL,
            created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at     DATETIME      NOT NULL,
            used_at        DATETIME,
            attempt_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            ip_address     VARCHAR(45),
            PRIMARY KEY (id),
            KEY idx_client (client_id),
            KEY idx_expires (expires_at)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_client_sessions (
            id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            client_id         INT UNSIGNED  NOT NULL,
            token_hash        CHAR(64)      NOT NULL,
            created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at        DATETIME      NOT NULL,
            last_accessed_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_address        VARCHAR(45),
            user_agent        VARCHAR(255),
            invalidated_at    DATETIME,
            PRIMARY KEY (id),
            UNIQUE KEY uq_token_hash (token_hash),
            KEY idx_client (client_id),
            KEY idx_expires (expires_at)
        ) ENGINE=InnoDB $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_client_access_log (
            id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            client_id   INT UNSIGNED,
            event       VARCHAR(60)   NOT NULL,
            ip_address  VARCHAR(45),
            meta        TEXT,
            created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_client (client_id),
            KEY idx_event  (event),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB $charset;";

        // ── Customization subsystem ───────────────────────────────────────────
        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_customizations (
            id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            setting_key   VARCHAR(100)  NOT NULL,
            setting_value LONGTEXT      NOT NULL,
            category      VARCHAR(50)   NOT NULL DEFAULT '',
            updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by    BIGINT UNSIGNED,
            PRIMARY KEY (id),
            UNIQUE KEY uq_setting_key (setting_key),
            KEY idx_category (category)
        ) ENGINE=InnoDB $charset;";

        // ── OPSMAIL Operational Intelligence Repository (v2.9.0) ──────────────
        //
        // opb_opsmail_queue is the canonical operational intelligence store.
        // All producers normalise events before insertion. Consumers (mail,
        // Telegram, analytics) read from this table and track their own status.
        //
        // event_uuid  — globally unique, immutable, idempotency key for consumers
        // source_system — OPB | WOOCOMMERCE | TRUSTED_ORIGIN | HUMAN_EMAIL
        // content_hash  — md5(event_type:entity_type:entity_id) for dedup
        // mail_status   — PENDING → SENT | FAILED; manually → ACKNOWLEDGED
        // telegram_status — PENDING → SENT | FAILED (future Telegram consumer)
        $tables[] = "CREATE TABLE {$wpdb->prefix}opb_opsmail_queue (
            id                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            event_uuid        CHAR(36)         NOT NULL,
            event_type        VARCHAR(60)      NOT NULL,
            source_system     VARCHAR(64)      NOT NULL DEFAULT 'OPB',
            entity_type       VARCHAR(60)      NOT NULL DEFAULT '',
            entity_id         INT UNSIGNED,
            branch_id         TINYINT UNSIGNED,
            user_id           BIGINT UNSIGNED,
            origin_type       ENUM('SYSTEM','TRUSTED_MAILBOX') NOT NULL DEFAULT 'SYSTEM',
            priority          VARCHAR(20)      NOT NULL DEFAULT 'NORMAL',
            subject           VARCHAR(250)     NOT NULL DEFAULT '',
            summary           TEXT,
            payload_json      LONGTEXT,
            content_hash      VARCHAR(64)      NULL,
            recipient_email   VARCHAR(200),
            mail_status       ENUM('PENDING','SENT','FAILED','ACKNOWLEDGED') NOT NULL DEFAULT 'PENDING',
            telegram_status   ENUM('PENDING','SENT','FAILED')                NOT NULL DEFAULT 'PENDING',
            mail_attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
            telegram_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error        TEXT,
            created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at           DATETIME,
            telegram_sent_at  DATETIME,
            PRIMARY KEY (id),
            UNIQUE KEY uq_event_uuid (event_uuid),
            KEY idx_mail_status (mail_status),
            KEY idx_event_type (event_type),
            KEY idx_created_at (created_at),
            KEY idx_branch (branch_id)
        ) ENGINE=InnoDB $charset;";

        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }

        // ── Idempotent post-dbDelta column & index upgrades ───────────────────
        //
        // IMPORTANT: Do NOT use "ADD COLUMN IF NOT EXISTS" or
        // "CREATE INDEX IF NOT EXISTS" here. Those are MariaDB / MySQL 8.0.3+
        // syntax. MySQL 5.7 (used on Hostinger shared hosting) rejects them
        // with a syntax error — $wpdb->query() returns false silently and
        // execution continues, leaving columns permanently missing.
        //
        // Use self::add_col() and self::idx_exists() instead. Both are
        // compatible with MySQL 5.6+ and are safe to call on every run.

        $invoices_t  = "{$wpdb->prefix}opb_invoices";
        $stays_t     = "{$wpdb->prefix}opb_booking_stays";
        $clients_t   = "{$wpdb->prefix}opb_clients";
        $bookings_t  = "{$wpdb->prefix}opb_bookings";

        // booking_stays: kennel_id (pre-v2.0.0)
        self::add_col( $stays_t, 'kennel_id', 'INT UNSIGNED NULL AFTER kennel' );

        // bookings: status column for Data Management cancel/restore (v2.6.0)
        self::add_col( $bookings_t, 'status', "VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER payment_status" );

        // invoices: doc_* columns for PDF engine
        self::add_col( $invoices_t, 'doc_token',        'VARCHAR(64)      NULL' );
        self::add_col( $invoices_t, 'doc_generated_at', 'DATETIME         NULL' );
        self::add_col( $invoices_t, 'doc_generated_by', 'BIGINT UNSIGNED  NULL' );
        self::add_col( $invoices_t, 'doc_pdf_path',     'VARCHAR(500)     NULL' );

        // Unique index on doc_token
        if ( ! self::idx_exists( $invoices_t, 'uq_invoice_doc_token' ) ) {
            $wpdb->query(
                "ALTER TABLE `{$invoices_t}` ADD UNIQUE KEY `uq_invoice_doc_token` (`doc_token`)"
            );
        }

        // Promote phone index to UNIQUE to enforce client identity.
        if ( ! self::idx_exists( $clients_t, 'uq_phone' ) ) {
            if ( self::idx_exists( $clients_t, 'idx_phone' ) ) {
                $wpdb->query( "ALTER TABLE `{$clients_t}` DROP INDEX `idx_phone`" );
            }
            $wpdb->query( "ALTER TABLE `{$clients_t}` ADD UNIQUE KEY `uq_phone` (`phone`)" );
        }

        // Invoice audit trail table (CREATE TABLE IF NOT EXISTS is standard SQL — safe on all versions)
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}opb_invoice_audit (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                invoice_id   INT UNSIGNED NOT NULL,
                event        ENUM('generated','regenerated','email_sent','whatsapp_shared') NOT NULL,
                performed_by BIGINT UNSIGNED NULL,
                performed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                meta         JSON NULL,
                PRIMARY KEY (id),
                KEY idx_invoice (invoice_id),
                KEY idx_event (event)
            ) ENGINE=InnoDB $charset"
        );

        // expenses: recorded_by_name convenience field (v2.7.0)
        $expenses_t = "{$wpdb->prefix}opb_expenses";
        self::add_col( $expenses_t, 'recorded_by_name', 'VARCHAR(150) NULL AFTER recorded_by' );

        // Seed default expense categories if table is empty (v2.7.0)
        $cat_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}opb_expense_categories" );
        if ( $cat_count === 0 ) {
            $defaults = ['Food','Medical','Grooming','Maintenance','Salary','Transport','Marketing','Other'];
            $order    = 0;
            foreach ( $defaults as $name ) {
                $wpdb->insert(
                    "{$wpdb->prefix}opb_expense_categories",
                    [ 'name' => $name, 'is_active' => 1, 'sort_order' => $order++ ],
                    [ '%s', '%d', '%d' ]
                );
            }
        }

        // ── OPSMAIL v2.9.0: dual-channel status architecture ──────────────────
        //
        // Existing installations: rename status → mail_status; add new columns.
        // New installations: CREATE TABLE above already has the correct schema.
        // All helpers use INFORMATION_SCHEMA — MySQL 5.7 compatible.

        $opsmail_t = "{$wpdb->prefix}opb_opsmail_queue";

        if ( $wpdb->get_var( $wpdb->prepare(
            'SHOW TABLES LIKE %s', $opsmail_t
        ) ) ) {
            // 1. Rename status → mail_status (column + index rename)
            if ( self::col_exists( $opsmail_t, 'status' ) && ! self::col_exists( $opsmail_t, 'mail_status' ) ) {
                $wpdb->query(
                    "ALTER TABLE `{$opsmail_t}` CHANGE COLUMN `status` `mail_status`
                     ENUM('PENDING','SENT','FAILED','ACKNOWLEDGED') NOT NULL DEFAULT 'PENDING'"
                );
                // After CHANGE COLUMN the old index still carries the name idx_status.
                // Drop it and add idx_mail_status for clarity.
                if ( self::idx_exists( $opsmail_t, 'idx_status' ) ) {
                    $wpdb->query( "ALTER TABLE `{$opsmail_t}` DROP INDEX `idx_status`" );
                }
            }
            if ( ! self::idx_exists( $opsmail_t, 'idx_mail_status' ) ) {
                $wpdb->query( "ALTER TABLE `{$opsmail_t}` ADD KEY `idx_mail_status` (`mail_status`)" );
            }

            // 2. New columns (idempotent via add_col)
            self::add_col( $opsmail_t, 'source_system',     "VARCHAR(64) NOT NULL DEFAULT 'OPB' AFTER event_type" );
            self::add_col( $opsmail_t, 'content_hash',      'VARCHAR(64) NULL AFTER payload_json' );
            self::add_col( $opsmail_t, 'telegram_status',   "ENUM('PENDING','SENT','FAILED') NOT NULL DEFAULT 'PENDING' AFTER mail_status" );
            self::add_col( $opsmail_t, 'telegram_attempts', 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER mail_attempts' );
            self::add_col( $opsmail_t, 'telegram_sent_at',  'DATETIME NULL AFTER sent_at' );
        }

        update_option( 'opb_db_version', OPB_VERSION );
    }

    // ── Schema upgrade helpers ─────────────────────────────────────────────────

    /**
     * Check whether a column exists in a table.
     * Uses INFORMATION_SCHEMA — compatible with MySQL 5.6+.
     */
    private static function col_exists( string $table, string $col ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table, $col
        ) );
    }

    /**
     * Check whether an index exists on a table.
     * Uses INFORMATION_SCHEMA — compatible with MySQL 5.6+.
     */
    private static function idx_exists( string $table, string $idx ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, $idx
        ) );
    }

    /**
     * Add a column to a table only if it does not already exist.
     * Idempotent; safe to call on every activation or upgrade run.
     */
    private static function add_col( string $table, string $col, string $def ): void {
        global $wpdb;
        if ( ! self::col_exists( $table, $col ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}" );
        }
    }
}
