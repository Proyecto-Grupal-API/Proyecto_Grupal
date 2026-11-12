#!/usr/bin/env python3
"""Offline checks only: OpenAPI references, Laravel route inventory and saved DTOs.
Requires jsonschema for Draft 2020-12 validation; never boots Laravel or connects to DB.
"""
from pathlib import Path
import argparse,json,re,sys
from urllib.parse import urlsplit
from jsonschema import Draft202012Validator,FormatChecker
from referencing import Registry
from referencing.jsonschema import DRAFT202012
ROOT=Path(__file__).resolve().parents[1]
METHODS={'get','post','put','patch','delete','head','options'}
def read(p):return json.loads(p.read_text())
def refs(node):
 if isinstance(node,dict):
  if '$ref'in node:yield node['$ref']
  for v in node.values():yield from refs(v)
 elif isinstance(node,list):
  for v in node:yield from refs(v)
def resolve(doc,pointer):
 if not pointer.startswith('#/'):raise ValueError('External/unresolved reference: '+pointer)
 node=doc
 for key in pointer[2:].split('/'):node=node[key.replace('~1','/').replace('~0','~')]
 return node
def main():
 parser=argparse.ArgumentParser();parser.add_argument('--spec',type=Path,default=ROOT/'docs/auditoria-tercer-parcial/comunidad.openapi.json');parser.add_argument('--routes',type=Path,default=ROOT/'docs/auditoria-tercer-parcial/contracts/runtime-routes.json');parser.add_argument('--output',type=Path);args=parser.parse_args();doc=read(args.spec);errors=[];ids=[];operations={}
 for pointer in refs(doc):
  try:resolve(doc,pointer)
  except (ValueError,KeyError):errors.append('Unresolved reference '+pointer)
 for name,schema in doc.get('components',{}).get('schemas',{}).items():
  try:Draft202012Validator.check_schema(schema)
  except Exception as e:errors.append('Invalid JSON Schema '+name+': '+str(e)[:250])
 for path,entry in doc['paths'].items():
  for method,op in entry.items():
   if method not in METHODS:continue
   ids.append(op['operationId']);operations[(path,method)]=op
   declared={p['name']for p in entry.get('parameters',[])+op.get('parameters',[])if p.get('in')=='path'}
   if declared!=set(re.findall(r'{([^}]+)}',path)):errors.append('Path parameters mismatch '+method+' '+path)
   for p in op.get('parameters',[]):
    if p.get('in')=='path'and p.get('required')is not True:errors.append('Optional path parameter '+path)
   if not op.get('responses'):errors.append('Missing responses '+path)
   for status,response in op.get('responses',{}).items():
    if not re.fullmatch(r'[1-5][0-9X]{2}|default',status):errors.append('Invalid response code '+status)
    if not response.get('description'):errors.append('Missing response description '+path)
 if len(ids)!=len(set(ids)):errors.append('Duplicate operationId')
 routes=read(args.routes);expected={('/'+r['uri'],m.lower())for r in routes for m in r['method'].split('|')if m!='HEAD'}
 for path,method in expected-set(operations):errors.append('Undocumented runtime route '+method+' '+path)
 for path,method in set(operations)-expected:errors.append('Route absent from runtime '+method+' '+path)
 # Saved sanitized real response DTOs. No new HTTP calls or mutation of original evidence.
 if not doc.get('openapi','').startswith('3.1.'):errors.append('Catalogue must declare OpenAPI3.1 for Draft2020-12 schemas')
 resource_doc=dict(doc, **{'$id':'urn:campus:comunidad'})
 registry=Registry().with_resource(resource_doc['$id'],DRAFT202012.create_resource(resource_doc))
 validator=Draft202012Validator(resource_doc,registry=registry,format_checker=FormatChecker())
 tested=0;skipped=0;failed=[];requests_tested=0;requests_redacted=0
 for f in [ROOT/'docs/integration/evidence/comunidad-90-2026-11-11/recorrido-http.json',ROOT/'docs/integration/evidence/comunidad-90-2026-11-11/reporte-visible-http.json']:
  if not f.exists():continue
  for row in read(f).get('records',[]):
   path=urlsplit(row['path']).path;op=None
   for (template,method),candidate in operations.items():
    if method!=row.get('method','GET').lower():continue
    pattern=re.sub(r'\\\{[^}]+\\\}',r'[^/]+',re.escape(template))
    if re.fullmatch(pattern,path):op=candidate;break
   if not op or op.get('x-contract-status')!='reviewed' or not isinstance(row.get('response'),(dict,list)):skipped+=1;continue
   response=op.get('responses',{}).get(str(row['status']),{});schema=response.get('content',{}).get('application/json',{}).get('schema')
   if not schema:skipped+=1;continue
   issues=list(validator.evolve(schema=schema).iter_errors(row['response']));tested+=1
   body_schema=op.get('requestBody',{}).get('content',{}).get('application/json',{}).get('schema')
   body=row.get('request')
   if body_schema and 200<=row['status']<300 and isinstance(body,(dict,list)):
    if '[omitido]'in json.dumps(body,ensure_ascii=False):requests_redacted+=1
    else:
     requests_tested+=1
     request_issues=list(validator.evolve(schema=body_schema).iter_errors(body))
     if request_issues:failed.append({'path':path,'status':row['status'],'kind':'request','errors':[str(e.message)[:220]for e in request_issues[:3]]})
   if issues:failed.append({'path':path,'status':row['status'],'errors':[str(e.message)[:220]for e in issues[:3]]})
 errors.extend('Saved DTO mismatch '+str(x)for x in failed)
 report={'operations':len(operations),'runtime_operations':len(expected),'reviewed':sum(o.get('x-contract-status')=='reviewed'for o in operations.values()),'partial':sum(o.get('x-contract-status')!='reviewed'for o in operations.values()),'saved_http_responses_checked':tested,'saved_http_responses_skipped':skipped,'saved_http_requests_checked':requests_tested,'saved_http_requests_redacted_skipped':requests_redacted,'errors':errors,'database_access':False,'application_code_executed':False}
 print(json.dumps(report,ensure_ascii=False,indent=2))
 if args.output:args.output.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
 return 1 if errors else 0
if __name__=='__main__':raise SystemExit(main())
