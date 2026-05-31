<?php
/**
 * Expenses import adapter.
 * Columns (XLSX): Expense, Time, Mode, Category, Amount, Amount (Inc. Tax)
 * Also handles older CSV format: Description, Amount, Mode, Category, Date, Branch
 * Requires context: branch_id
 */
class OPB_Expenses_Adapter extends OPB_Import_Adapter {

    public function entity(): string { return 'expenses'; }

    public function column_groups(): array {
        return [
            '*Description'   => ['Expense','Description','description'],
            '*Time'          => ['Time','Date','date','expense_at'],
            'Mode'           => ['Mode','mode'],
            'Category'       => ['Category','category'],
            '*Amount'        => ['Amount','amount'],
            'Amount Inc. Tax'=> ['Amount (Inc. Tax)','amount_inc_tax'],
        ];
    }

    protected function process_row( array $row, int $row_num, bool $dry, array $ctx ): array {
        global $wpdb;

        // Branch: context-first, then CSV column (legacy CSV format has Branch column)
        $branch_id = (int)($ctx['branch_id'] ?? 0);
        if ( ! $branch_id ) {
            $raw_branch = $this->col($row, ['Branch','branch']);
            if ( $raw_branch ) {
                $resolver  = OPB_Branch_Resolver::from_db();
                $resolved  = $resolver->resolve($raw_branch);
                $branch_id = $resolved['branch'] ? (int)$resolved['branch']->id : 0;
            }
        }
        if ( ! $branch_id ) {
            return ['status'=>'skipped','reason_code'=>'missing_branch',
                'detail'=>"branch_id required — pass as context or add a Branch column"];
        }

        $desc = $this->col($row, ['Expense','Description','description']);
        if ( ! $desc ) {
            return ['status'=>'skipped','reason_code'=>'missing_description','detail'=>"Row $row_num: no description/expense value"];
        }

        $amount_raw = $this->col($row, ['Amount','amount']);
        if ( $amount_raw === '' ) {
            return ['status'=>'skipped','reason_code'=>'missing_amount','detail'=>"Row $row_num: Amount is empty"];
        }

        if ( ! $dry ) {
            $time_raw   = $this->col($row, ['Time','Date','date','expense_at']);
            $expense_at = $this->parse_datetime($time_raw) ?? $this->parse_date($time_raw) ?? current_time('Y-m-d H:i:s');
            $amt_inc    = $this->col($row, ['Amount (Inc. Tax)','amount_inc_tax']);
            $mode_raw   = $this->col($row, ['Mode','mode'], 'Cash');

            $wpdb->insert("{$wpdb->prefix}opb_expenses", [
                'branch_id'      => $branch_id,
                'description'    => sanitize_text_field($desc),
                'expense_at'     => $expense_at,
                'mode'           => $this->normalise_mode($mode_raw),
                'category'       => sanitize_text_field($this->col($row,['Category','category'])) ?: null,
                'amount'         => (float)$amount_raw,
                'amount_inc_tax' => $amt_inc !== '' ? (float)$amt_inc : null,
            ]);
        }

        return ['status'=>'imported','reason_code'=>'','detail'=>''];
    }
}
