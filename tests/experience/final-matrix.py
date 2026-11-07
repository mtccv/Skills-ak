#!/usr/bin/env python3
"""Resolve every initial failing case to its last executed evidence, without double-counting."""
from pathlib import Path
import json

root=Path(__file__).resolve().parents[2]
results=Path(__file__).parent/'results'
for v in ['83','84']:
    for name in ['help','campaigns','campaign-regression']:
        source=results/f'{name}-php{v}.json'
        target=results/f'{name}-initial-php{v}.json'
        if source.exists() and not target.exists():source.rename(target)

matrix=[]
def merge(runtime,domain,files,kind='Real WordPress/MySQL'):
    latest={}
    for file in files:
        report=json.loads((results/file).read_text())
        for case in report['cases']:
            entry={'runtime':runtime,'domain':domain,'id':case['id'],'status':case['status'],'evidence':f'tests/experience/results/{file}','environment_kind':kind}
            if 'reason'in case:entry['reason']=case['reason']
            if case['id']in latest:entry['supersedes']=latest[case['id']]['evidence']
            latest[case['id']]=entry
    matrix.extend(latest.values())

for v in ['83','84']:
    merge(v,'onboarding',[f'onboarding-php{v}.json'])
    merge(v,'help',[f'help-initial-php{v}.json',f'help-regression-php{v}.json'])
    merge(v,'installation',[f'installation-php{v}.json'])
    merge(v,'subscriptions',[f'subscriptions-php{v}.json'],'Real WordPress/MySQL with intercepted Mock mail transport')
    merge(v,'campaigns',[f'campaigns-initial-php{v}.json',f'campaign-regression-initial-php{v}.json',f'mail-public-regression-php{v}.json',f'new-resource-mail-php{v}.json'],'Real WordPress/MySQL; test-constrained owned outbox selection and Mock mail acceptance')
    merge(v,'guards',[f'guards-php{v}.json'])
    merge(v,'concurrency',[f'concurrency-php{v}.json'],'Real independent PHP/MySQL processes')
    merge(v,'mail-concurrency',[f'mail-concurrency-php{v}.json'],'Real independent PHP/MySQL processes and explicit barriers; intercepted Mock transport')
    merge(v,'retention',[f'subscription-retention-php{v}.json'])
    for directory,names in [('operations',['users','notifications-mail','workbench-home','analytics','transactions']),('commercial',['sponsors','enterprise','entitlements-bundles','privacy-transactions'])]:
        for name in names:merge(v,f'regression-{directory}-{name}',[f'regression-{directory}-{name}-php{v}.json'],'Real WordPress/MySQL; existing suite mail outcomes remain Mock where used')
    files=[f'browser-php{v}.json']if v=='83'else[f'browser-public-php{v}.json',f'browser-newsletter-php{v}.json']
    merge(v,'browser',files,'Real Chromium HTTP/UI against WordPress/MySQL; synthetic credentials and private token pipe, no real email delivery')

retained={v:json.loads((results/f'retained-data-php{v}.json').read_text())for v in ['83','84']}
infrastructure={
    'activation_preservation':{v:{k:json.loads((results/f'preservation-php{v}.json').read_text())[k]for k in ['passed','failed']}for v in ['83','84']},
    'native_core_assertions':{v:sum(line.startswith('PASS: ')for line in (results/f'core-php{v}.txt').read_text().splitlines())for v in ['83','84']},
    'package_checks':{stage:{k:json.loads((results/f'package-{stage}.json').read_text())[k]for k in ['passed','failed']}for stage in ['baseline','upgrade','verify']},
    'package_byte_comparison':{k:json.loads((results/'runtime-package-hashes.json').read_text())[k]for k in ['package_sha256','package_files','passed','failed']},
}
report={'scope':'Phase14 targeted development QA only; Final QA2.0 remains unstarted','case_identity':'runtime + domain + id; final executed evidence supersedes prior case outcome, never double-counted','cases':matrix,'passed':sum(c['status']=='passed'for c in matrix),'failed':sum(c['status']!='passed'for c in matrix),'per_runtime':{v:{'passed':sum(c['runtime']==v and c['status']=='passed'for c in matrix),'failed':sum(c['runtime']==v and c['status']!='passed'for c in matrix)}for v in ['83','84']},'infrastructure_checks_separate_from_business_case_count':infrastructure,'original_data_differences_not_hidden_or_counted_as_pass':{v:{'matched_groups':d['matched_groups'],'mismatched_groups':d['mismatched_groups'],'differences':d['differences'],'evidence':f'tests/experience/results/retained-data-php{v}.json'}for v,d in retained.items()},'unexecuted_external_dependencies':['Real SMTP and end-recipient delivery','Real HTTPS RFC8058 transport behavior','Licensed WPML','Live GitHub and AI APIs','Actual current SEO-plugin combinations','Persistent cache/CDN','Production TLS/load, multisite and all-browser/device matrix'],'historical_failures':['Initial onboarding harness invoked erasure extension without complete native lifecycle; corrected to actual native deletion, then full confirmed eraser independently tested.','Initial help test expected a request-local Catalog instance to invalidate across an admin archive; corrected to fresh next-request public projection.','Initial campaign selector expected obsolete ORDER BY CASE, and HTTP local mail expectation incorrectly required HTTPS-only RFC8058 header; corrected selectors and actual unsent digest regressions executed.','Initial pause test expected retry; actual paused known-unsent contract returns queued without consuming attempts; corrected and reexecuted.','Actual P1 native privacy erasure failed because a later module required an earlier module marker after its removal; fixed with identity-bound erasure context, real native regression passed.','Actual P2 native Help/Subscriptions admin headers were emitted after HTML; fixed at load hook and real browser regression passed.','Initial browser submission selector selected logout form; corrected harness and real owned draft flow reexecuted.']}
(results/'final-case-matrix.json').write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
print(json.dumps({k:report[k]for k in ['passed','failed','per_runtime','original_data_differences_not_hidden_or_counted_as_pass']},ensure_ascii=False))
raise SystemExit(bool(report['failed']))
