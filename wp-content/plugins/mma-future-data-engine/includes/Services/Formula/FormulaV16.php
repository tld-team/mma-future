<?php
namespace MMAF\DataEngine\Services\Formula;

final class FormulaV16 {
    public const VERSION='v1.6';
    public const PERIOD_START='2026-05-09';
    public const SCOPE='professional_mma_period_20260509_v1';
    public static function config(): array {
        $config=FormulaV15::config();
        $config['formula_version']=self::VERSION;
        $config['scoring_scope']=['sport'=>'professional_mma','start_date'=>self::PERIOD_START,'end_date'=>'reference_date','inclusive'=>true,'career_eligibility'=>true,'max_verification_age_days'=>7,'minimum_sample'=>'win_loss_bouts'];
        return $config;
    }
}
