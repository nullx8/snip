<?php
	
	require_once(__DIR__.'/../jsondiff.inc.php');

$a = '{"v":1,"profile":"investor","broker":{"code":"enfoid","account_ref":"3044946","public_name":"EnFoid"},"funding":{"lp_id":40,"product":"IFM","profit_share_bps":2000},"balances":{"currency":"USD","initial":25000,"collateral":1000},"risk":{"overall":{"mode":"relative","value_bps":600},"daily":{"mode":"absolute","value_bps":400}},"payout":{"min_age_days":2,"min_profit_abs":25,"min_profit_bps":500,"consistency":{"threshold_bps":1500}}}';

$b = '{
    "v": 1,
    "profile": "investor",
    "broker": {
        "code": "enfoid",
        "account_ref": "3044946",
        "public_name": "EnFoid"
    },
    "funding": {
        "lp_id": 40,
        "product": "IFM",
        "profit_share_bps": 2000
    },
    "balances": {
        "currency": "USD",
        "initial": 25000,
        "collateral": 1000
    },
    "risk": {
        "overall": {
            "mode": "relative",
            "value_bps": 600
        },
        "daily": {
            "mode": "absolute",
            "value_bps": 400
        }
    },
    "payout": {
        "min_age_days": 2,
        "min_profit_abs": 25,
        "min_profit_bps": 500,
        "consistency": {
            "threshold_bps": 1500
        }
    }
}';

print_r(jsondiff_compare($a,$b));