#!/usr/bin/env python3
"""Rebuild existing catalogue from reviewed fragments and the read-only route snapshot. No DB access."""
from pathlib import Path
import json,copy,re
root=Path(__file__).resolve().parents[1];folder=root/'docs/auditoria-tercer-parcial/contracts';specpath=root/'docs/auditoria-tercer-parcial/comunidad.openapi.json'
# Snapshot from read-only route:list, no app/database bootstrap in the validator.
routes=json.loads((folder/'runtime-routes.json').read_text())
base=json.loads(specpath.read_text());expected={('/'+r['uri'],m.lower()):r for r in routes for m in r['method'].split('|')if m!='HEAD'}
for key,r in expected.items():
 path,method=key
 if method not in base['paths'].get(path,{}):
  sample={'x-contract-status':'partial','description':'Ruta nueva sin revisión manual de payload; no hereda esquemas de otra operación.','security':[{'sessionCookie':[]}]if '/portal/'in path else[{'serviceBearer':[]}],'responses':{'200':{'description':'Payload pendiente de documentar','content':{'application/json':{'schema':{}}}}}};sample['operationId']=method+'_'+re.sub('[^a-zA-Z0-9]+','_',path).strip('_');sample['summary']=r['action'];sample['parameters']=[{'name':n,'in':'path','required':True,'schema':{'type':'string'}}for n in re.findall(r'{([^}]+)}',path)];base['paths'].setdefault(path,{})[method]=sample
seen={}
for fragment in sorted(folder.glob('*.fragment.json')):
 d=json.loads(fragment.read_text())
 for path,ops in d['paths'].items():
  for method,op in ops.items():
   assert (path,method)in expected,(fragment,path,method)
   base['paths'].setdefault(path,{})[method]=op
 base.setdefault('components',{}).setdefault('securitySchemes',{}).update(d.get('components',{}).get('securitySchemes',{}))
 for key,value in d.get('components',{}).get('schemas',{}).items():
  target=base.setdefault('components',{}).setdefault('schemas',{})
  if key in seen and seen[key]!=value:raise ValueError('Schema collision between fragments '+key)
  seen[key]=value
  target[key]=value
def normalize(node):
 if isinstance(node,list):return [normalize(v)for v in node]
 if not isinstance(node,dict):return node
 node={k:normalize(v)for k,v in node.items()}
 if node.pop('nullable',False):return {'anyOf':[node,{'type':'null'}]}
 return node
base=normalize(base)
base['openapi']='3.1.0'
base['info']['version']='2026.11.11';base['info']['description']='Catálogo contrastado con route:list del 11 de noviembre. x-contract-status distingue esquemas revisados de pendientes; no equivale a aceptación integral de E1 ni a pruebas nuevas de negocio.'
# Only these generated properties change; all application routes and auth remain untouched.
specpath.write_text(json.dumps(base,ensure_ascii=False,indent=2)+'\n')
print('Assembled',len(list(folder.glob('*.fragment.json'))),'fragments')

# Human-readable status for reviewers; avoid equating route coverage with strict contracts.
operations=[(path,method,op)for path,entry in base['paths'].items()for method,op in entry.items()if method in ['get','post','put','patch','delete','head','options']]
reviewed=sum(op.get('x-contract-status')=='reviewed'for _,_,op in operations)
lines=['# API de Comunidad: inventario y estado de contratos','','Corte documental: 11 de noviembre de 2026. Fuente de rutas: `contracts/runtime-routes.json`.','','- Operaciones inventariadas: **'+str(len(operations))+'** (HEAD implícito excluido).','- Operaciones con esquema revisado: **'+str(reviewed)+'**.','- Operaciones parciales o pendientes: **'+str(len(operations)-reviewed)+'**.','','La cobertura de rutas no implica aceptación integral. `reviewed` acredita revisión estática y validación documental; únicamente las respuestas históricas contrastadas en el reporte tienen evidencia de DTO ejecutado.','Los contratos usan OpenAPI3.1 y distinguen sesión/CSRF del portal y OAuth de servicios. No habilitan fondos, concesiones o transportes al importarlos.','','Construcción y verificación: véase [contracts/README.md](contracts/README.md). El [OpenAPI](comunidad.openapi.json) contiene los esquemas, fuentes, reglas y estados por operación.','','| Método | Ruta | Estado del contrato |','|---|---|---|']
for path,method,op in sorted(operations):lines.append('| '+method.upper()+' | `'+path+'` | '+('Revisado'if op.get('x-contract-status')=='reviewed'else'Parcial / pendiente')+' |')
(root/'docs/auditoria-tercer-parcial/API.md').write_text('\n'.join(lines)+'\n')
