<?php
/**
 * Fired during plugin activation.
 * Creates all custom database tables.
 */
class OPB_Activator {

    public static function activate(): void {
        self::create_tables();
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

        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }

        // Add kennel_id column to existing booking_stays tables (idempotent)
        $wpdb->query(
            "ALTER TABLE {$wpdb->prefix}opb_booking_stays
             ADD COLUMN IF NOT EXISTS kennel_id INT UNSIGNED NULL AFTER kennel"
        );

        update_option( 'opb_db_version', OPB_VERSION );
    }
}
