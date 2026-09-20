#!/usr/bin/env python3
"""Verify an actual Cardify backup inside a bounded, disposable database.

No production database connection, app bootstrap, published ports, host bind
mounts or persistent volumes are permitted. The cached image is pinned; this
command never pulls an image. Data and raw diagnostics stay on this host.
An absent, stale, encrypted-but-unconfigured or unrestorable backup is a failure.
"""
import datetime, gzip, hashlib, json, os, pathlib, re, subprocess, time, uuid

RESTORE_IMAGE = 'sha256:99d774bf02a48a1bb1c599920d2571946d31e5940b854b02737d5e95c184358f'

def main():
    run_id = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + uuid.uuid4().hex[:8]
    os.umask(0o077)
    root = pathlib.Path('/var/lib/cardify-backup-verification') / run_id
    root.mkdir(parents=True, exist_ok=False, mode=0o700)
    events = []
    name = 'cardify-restore-' + run_id

    def record(label, **fields):
        events.append(dict(label=label, utc=datetime.datetime.now(datetime.timezone.utc).isoformat(), **fields))
        (root / 'result.json').write_text(json.dumps(events, indent=2))
        print(json.dumps(events[-1]), flush=True)

    def command(args, label, timeout=60, input=None):
        r = subprocess.run(args, input=input, capture_output=True, timeout=timeout)
        if r.returncode:
            (root / (label + '.error')).write_bytes(r.stderr)
            (root / (label + '.output')).write_bytes(r.stdout)
            raise RuntimeError(label + ' failed; diagnostic retained privately')
        return r.stdout

    assert os.geteuid() == 0
    files = sorted(pathlib.Path('/var/backups/cardify').glob('cardify-*.sql.gz*'), key=lambda p:p.stat().st_mtime, reverse=True)
    assert files, 'no database backup available'
    backup = files[0]
    assert not backup.is_symlink(), 'backup symlink refused'
    assert backup.name.endswith('.sql.gz'), 'encrypted restore requires an approved recovery identity'
    assert time.time() - backup.stat().st_mtime < 172800, 'latest backup is older than 48 hours'
    assert backup.stat().st_size < 100_000_000
    record('source', name=backup.name, sha256=hashlib.sha256(backup.read_bytes()).hexdigest(), bytes=backup.stat().st_size)
    with gzip.open(backup, 'rb') as f:
        data = f.read(256_000_001)
    assert len(data) <= 256_000_000, 'backup exceeds bounded restore size'
    expected = set(re.findall(rb'^CREATE TABLE (?:IF NOT EXISTS )?`([a-zA-Z0-9_]+)`', data, re.M))
    assert len(expected) >= 20
    record('gzip_validated', bytes=len(data), table_count=len(expected), uncompressed_sha256=hashlib.sha256(data).hexdigest())
    assert os.getloadavg()[0] < max(2, os.cpu_count() or 1), 'restore deferred due to host load'
    image_id = command(['docker', 'image', 'inspect', RESTORE_IMAGE, '--format', '{{.Id}}'], 'image').decode().strip()
    started = False
    try:
        command(['docker', 'run', '--detach', '--name', name, '--network', 'none', '--read-only',
                 '--user', '999:999', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
                 '--cpus', '0.5', '--memory', '1g', '--memory-swap', '1g', '--pids-limit', '100',
                 '--tmpfs', '/var/lib/mysql:rw,nosuid,nodev,noexec,size=768m,uid=999,gid=999,mode=0700',
                 '--tmpfs', '/var/run/mysqld:rw,nosuid,nodev,noexec,size=16m,uid=999,gid=999,mode=0700',
                 '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,size=64m,uid=999,gid=999,mode=1777',
                 '--tmpfs', '/var/lib/mysql-files:rw,nosuid,nodev,noexec,size=1m,uid=999,gid=999,mode=0700',
                 '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=1', '--env', 'MYSQL_DATABASE=bc', image_id,
                 '--skip-networking', '--event-scheduler=OFF', '--local-infile=OFF', '--skip-log-bin',
                 '--performance-schema=OFF', '--innodb-buffer-pool-size=128M', '--max-connections=5'], 'start')
        started = True
        state=json.loads(command(['docker','inspect',name], 'inspect'))[0]
        h=state['HostConfig']
        assert h['NetworkMode']=='none' and h['ReadonlyRootfs'] and not h.get('Binds') and not h.get('PortBindings')
        assert h['CapDrop']==['ALL'] and h['Memory']==1073741824 and state['Config']['User']=='999:999'
        record('isolation_verified', image=image_id, network='none', read_only_root=True, host_binds=0,
               ports=0, capabilities='all dropped', cpu_limit=0.5, memory_limit_mib=1024, storage='tmpfs only')
        ready=False
        for _ in range(90):
            p=subprocess.run(['docker','exec',name,'mysql','--protocol=socket','-uroot','-NBe','SELECT 1 FROM information_schema.schemata WHERE schema_name="bc"'],capture_output=True,timeout=5)
            if p.returncode==0 and p.stdout.strip()==b'1':
                # Initialization uses a temporary server; wait for the final entrypoint.
                proc=subprocess.run(['docker','exec',name,'cat','/proc/1/comm'],capture_output=True,timeout=5)
                if proc.stdout.strip()==b'mysqld':
                    ready=True
                    break
            time.sleep(2)
        assert ready, 'isolated database did not become ready'
        record('database_ready')
        command(['docker','exec','-i',name,'mysql','--protocol=socket','-uroot','bc'], 'import', timeout=180, input=data)
        del data
        tables=command(['docker','exec',name,'mysql','--protocol=socket','-uroot','-NBe',
                        "SELECT table_name FROM information_schema.tables WHERE table_schema='bc' AND table_type='BASE TABLE'"], 'tables').splitlines()
        actual=set(tables)
        assert actual==expected, 'restored table set differs from dump'
        counts={}
        for table in ['companies','employees','templates','payments','scanned_contacts']:
            if table.encode() not in actual:
                continue
            value=command(['docker','exec',name,'mysql','--protocol=socket','-uroot','-NBe',f'SELECT COUNT(*) FROM `bc`.`{table}`'], 'counts').strip()
            assert value.isdigit()
            counts[table]=int(value)
        record('import_completed', table_set_matches=True, tables=len(actual), aggregate_counts=counts)
        check_sql='CHECK TABLE '+','.join('`bc`.`'+t.decode('ascii')+'`' for t in sorted(actual))
        check=command(['docker','exec',name,'mysql','--protocol=socket','-uroot','--batch','--skip-column-names','-e',check_sql], 'integrity', timeout=120)
        statuses=[line.split(b'\t') for line in check.splitlines() if line.strip()]
        assert len(statuses)==len(actual) and all(s[-2:]==[b'status',b'OK'] for s in statuses), 'integrity check has non-OK statuses'
        flags=command(['docker','exec',name,'mysql','--protocol=socket','-uroot','-NBe',
                       'SELECT @@event_scheduler,@@local_infile,@@skip_networking'], 'runtime_flags').decode().strip()
        assert flags=='OFF\t0\t1'
        record('restore_passed', tables=len(actual), integrity_checks=len(statuses), aggregate_counts=counts,
               events_disabled=True, local_infile_disabled=True, network_disabled=True)
    except Exception as exc:
        record('restore_failed', error=str(exc))
        raise
    finally:
        if started:
            p=subprocess.run(['docker','logs',name],capture_output=True,timeout=10)
            (root/'container.log').write_bytes(p.stdout+p.stderr)
            p=subprocess.run(['docker','rm','--force','--volumes',name],capture_output=True,timeout=30)
            assert p.returncode==0, 'isolated container cleanup failed'
            exists=subprocess.run(['docker','inspect',name],capture_output=True,timeout=10).returncode==0
            record('cleanup', container_absent=not exists, persistent_volume_created=False, source_backup_unchanged=hashlib.sha256(backup.read_bytes()).hexdigest()==events[0]['sha256'])
            assert not exists

if __name__ == '__main__':
    main()
