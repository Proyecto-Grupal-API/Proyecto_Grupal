#!/usr/bin/env python3
"""Prepare the alternate checkout's private SQL service; never enables payments/E7."""
from pathlib import Path
import os
import re
import secrets
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]
COMPOSE = ['docker', 'compose', '--project-directory', str(ROOT), '-f', str(ROOT / 'compose.integracion.yaml')]
ENV = ROOT / '.env'
if not ENV.is_file():
    raise SystemExit('Configura primero el .env del alterno. No se creó ni reemplazó.')
content = ENV.read_text()
values = dict(re.findall(r'^([A-Z0-9_]+)=(.*)$', content, re.M))
for key in ['SQLSERVER_SA_PASSWORD', 'SQLSRV_PASSWORD']:
    if not values.get(key):
        values[key] = 'Campus!' + secrets.token_hex(24)
        content = re.sub(r'^' + key + r'=.*\n?', '', content, flags=re.M)
        content += '\n' + key + '=' + values[key] + '\n'
for key, value in {'SQLSRV_HOST': 'sqlserver', 'SQLSRV_PORT': '1433',
                   'SQLSRV_DATABASE': 'campus_digital_financial',
                   'SQLSRV_USERNAME': 'campus_integracion'}.items():
    current = values.get(key)
    if current and current != value:
        raise SystemExit('Configuración SQL ajena al alterno; revisa ' + key + ' antes de continuar.')
    if not current:
        content += '\n' + key + '=' + value + '\n'
ENV.write_text(content)
os.chmod(ENV, 0o600)
subprocess.run([*COMPOSE, 'up', '-d', '--no-deps', 'sqlserver'], cwd=ROOT, check=True)
container = 'campus_integracion_e1-sqlserver-1'
project = subprocess.check_output(['docker', 'inspect', '-f', '{{index .Config.Labels "com.docker.compose.project"}}', container], text=True).strip()
if project != 'campus_integracion_e1':
    raise SystemExit('El contenedor no pertenece al alterno. Operación bloqueada.')
password = values['SQLSRV_PASSWORD'].replace("'", "''")
query = f"""IF DB_ID('campus_digital_financial') IS NULL CREATE DATABASE campus_digital_financial;
GO
IF DB_ID('campus_digital_financial_testing') IS NULL CREATE DATABASE campus_digital_financial_testing;
GO
IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name='campus_integracion') CREATE LOGIN campus_integracion WITH PASSWORD='{password}';
GO
USE campus_digital_financial;
IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name='campus_integracion') CREATE USER campus_integracion FOR LOGIN campus_integracion;
ALTER ROLE db_owner ADD MEMBER campus_integracion;
GO
USE campus_digital_financial_testing;
IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name='campus_integracion') CREATE USER campus_integracion FOR LOGIN campus_integracion;
ALTER ROLE db_owner ADD MEMBER campus_integracion;
GO
"""
command = ['docker', 'exec', '-i', container, 'sh', '-c',
           'SQLCMDPASSWORD="$MSSQL_SA_PASSWORD" exec /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -C -b']
for attempt in range(30):
    result = subprocess.run(command, input=query, text=True, capture_output=True)
    if result.returncode == 0:
        print('SQL del alterno preparado. Credenciales privadas. Pagos y E7 no se activaron.')
        break
    time.sleep(2)
else:
    raise SystemExit('SQL no quedó disponible. No se muestran consultas privadas; revisa el contenedor.')
