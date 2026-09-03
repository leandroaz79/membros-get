(function () {
    // Test DB connection
    const btnTest = document.getElementById('btn-test-db');
    const formDb = document.getElementById('form-database');
    const resultEl = document.getElementById('db-test-result');
    const formApp = document.getElementById('form-app');

    if (btnTest && formDb && resultEl) {
        btnTest.addEventListener('click', async function () {
            const get = (name) => (formDb.querySelector('[name="' + name + '"]')?.value || '').trim();
            const host = get('db_host') || '127.0.0.1';
            const port = get('db_port') || '3306';
            const database = get('db_database');
            const username = get('db_username');
            const password = get('db_password');
            if (!database || !username) {
                resultEl.className = 'text-sm rounded-lg p-3 bg-red-500/10 text-red-600';
                resultEl.textContent = 'Preencha o nome do banco e usuário.';
                resultEl.classList.remove('hidden');
                return;
            }
            btnTest.disabled = true;
            btnTest.textContent = 'Testando...';
            resultEl.classList.add('hidden');
            try {
                const r = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        action: 'test-db',
                        db_host: host,
                        db_port: port,
                        db_database: database,
                        db_username: username,
                        db_password: password
                    })
                });
                const text = await r.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (_) {
                    throw new Error('Resposta inválida: ' + (text.slice(0, 100) || r.statusText));
                }
                resultEl.classList.remove('hidden');
                if (data.success) {
                    resultEl.className = 'text-sm rounded-lg p-3 bg-green-500/10 text-green-600 dark:text-green-400';
                    resultEl.textContent = 'Conexão bem-sucedida!';
                } else {
                    resultEl.className = 'text-sm rounded-lg p-3 bg-red-500/10 text-red-600 dark:text-red-400';
                    resultEl.textContent = data.message || 'Falha na conexão.';
                }
            } catch (e) {
                resultEl.classList.remove('hidden');
                resultEl.className = 'text-sm rounded-lg p-3 bg-red-500/10 text-red-600 dark:text-red-400';
                resultEl.textContent = 'Erro: ' + (e.message || 'Falha ao conectar.');
            }
            btnTest.disabled = false;
            btnTest.textContent = 'Testar conexão';
        });
    }

    if (formDb) {
        formDb.addEventListener('submit', function (e) {
            if (!formDb.db_database?.value?.trim() || !formDb.db_username?.value?.trim()) {
                e.preventDefault();
                alert('Preencha o nome do banco e usuário.');
            }
        });
    }

    // Step 4: Run install via API (em etapas para evitar timeout)
    if (formApp) {
        formApp.addEventListener('submit', async function (e) {
            e.preventDefault();
            const formData = new FormData(formApp);
            const baseData = {
                action: 'install-step',
                db_host: formData.get('db_host'),
                db_port: formData.get('db_port'),
                db_database: formData.get('db_database'),
                db_username: formData.get('db_username'),
                db_password: formData.get('db_password'),
                app_name: formData.get('app_name'),
                app_url: formData.get('app_url'),
                app_env: formData.get('app_env'),
                session_driver: formData.get('session_driver')
            };
            const statusEl = document.getElementById('install-status');
            const barEl = document.getElementById('install-bar');
            const logEl = document.getElementById('install-log');
            const errEl = document.getElementById('install-error');
            const okEl = document.getElementById('install-success');
            const progressWrap = document.getElementById('install-progress-wrap');
            const progressInner = progressWrap ? progressWrap.querySelector('div:first-child') : null;
            formApp.classList.add('hidden');
            if (progressWrap) progressWrap.classList.remove('hidden');

            const labels = [
                'Criando .env e instalando dependências (Composer)...',
                'Gerando chave da aplicação...',
                'Instalando assets (npm build)...',
                'Finalizando (cache, lock)...',
            ];
            const migrateLabel = 'Executando migrações do banco (pode levar vários minutos)...';
            const hints = ['Pode levar 2–5 min na primeira vez. Aguarde.', '', '', ''];
            const migrateHint = 'Cada lote evita timeout da hospedagem. Não feche esta página.';

            const callStep = async (step) => {
                const r = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ ...baseData, step })
                });
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (_) {
                    const msg = text.trim() ? text.slice(0, 200) : (r.status === 200 ? 'Resposta vazia (possível timeout – tente aumentar max_execution_time no PHP)' : 'Status ' + r.status + ': ' + r.statusText);
                    throw new Error('Resposta inválida: ' + msg);
                }
            };

            const callMigrateChunk = async () => {
                const r = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ action: 'migrate-chunk', chunk: 8 })
                });
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (_) {
                    throw new Error('Resposta inválida nas migrations: ' + (text.trim().slice(0, 200) || r.statusText));
                }
            };

            const appendLog = (chunk) => {
                if (!logEl || !chunk) return;
                const line = typeof chunk === 'string' ? chunk : (chunk.log || chunk.message || '');
                if (!line.trim()) return;
                logEl.textContent = (logEl.textContent ? logEl.textContent + '\n' : '') + line.trim();
                logEl.classList.remove('hidden');
            };

            try {
                for (let step = 1; step <= 4; step++) {
                    if (statusEl) {
                        statusEl.textContent = labels[step - 1];
                        statusEl.title = hints[step - 1] || '';
                    }
                    if (progressWrap) {
                        const hint = progressWrap.querySelector('.install-step-hint');
                        if (hint) hint.textContent = hints[step - 1] || '';
                    }
                    if (barEl) barEl.style.width = Math.max(5, (step - 1) * 20 + 5) + '%';

                    const res = await callStep(step);
                    if (!res.success) {
                        if (errEl) {
                            errEl.textContent = res.message || 'Erro na instalação.';
                            errEl.classList.remove('hidden');
                        }
                        appendLog(res.log);
                        return;
                    }

                    if (step === 2) {
                        let migrateDone = false;
                        let migrateLoops = 0;
                        const maxLoops = 200;
                        while (!migrateDone && migrateLoops < maxLoops) {
                            migrateLoops++;
                            if (statusEl) {
                                statusEl.textContent = migrateLabel;
                            }
                            const mig = await callMigrateChunk();
                            appendLog(mig.log || mig.message);
                            if (!mig.success) {
                                if (errEl) {
                                    errEl.textContent = mig.message || 'Falha nas migrations.';
                                    if (mig.failed_migration) {
                                        errEl.textContent += ' (parou em: ' + mig.failed_migration + ')';
                                    }
                                    errEl.classList.remove('hidden');
                                }
                                return;
                            }
                            migrateDone = !!mig.done;
                            const pending = typeof mig.pending === 'number' ? mig.pending : 0;
                            if (progressWrap) {
                                const hint = progressWrap.querySelector('.install-step-hint');
                                if (hint) {
                                    hint.textContent = migrateDone
                                        ? 'Banco de dados pronto.'
                                        : (pending > 0 ? pending + ' migrations restantes...' : migrateHint);
                                }
                            }
                            if (barEl) {
                                const base = 40;
                                const progress = migrateDone ? 20 : Math.min(18, migrateLoops * 2);
                                barEl.style.width = (base + progress) + '%';
                            }
                        }
                        if (!migrateDone) {
                            if (errEl) {
                                errEl.textContent = 'Migrations não concluíram a tempo. Recarregue a página e tente continuar, ou rode migrations em Configurações > Update.';
                                errEl.classList.remove('hidden');
                            }
                            return;
                        }
                    }

                    if (barEl) barEl.style.width = step * 20 + '%';
                }
                if (barEl) barEl.style.width = '100%';
                if (statusEl) statusEl.textContent = 'Concluído!';
                if (progressInner) progressInner.classList.add('hidden');
                if (okEl) okEl.classList.remove('hidden');
                setTimeout(() => { window.location.href = '/'; }, 1500);
            } catch (err) {
                if (errEl) {
                    errEl.textContent = 'Erro: ' + (err.message || 'Falha na requisição.');
                    errEl.classList.remove('hidden');
                }
            }
        });
    }
})();
