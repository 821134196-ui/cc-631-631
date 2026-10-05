import React, { useEffect, useState } from 'react';
import { api, CHANNEL_LABELS, CHANNELS, RECEIPT_STATUS, TASK_STATUS, fmt } from '../api';

function ChannelStateCard({ state }) {
    const c = state.content;
    return (
        <div className="channel-card">
            <div className="channel-name">{state.label}</div>
            <div className="channel-summary">{c?.summary || '常规营业时间'}</div>
            <div className="muted small">
                已应用发布 #{state.applied_epoch || '—'}{c?.applied_at ? ` · ${fmt(c.applied_at)}` : ''}
            </div>
        </div>
    );
}

function ReceiptChip({ receipt, onRetry, busy }) {
    const st = RECEIPT_STATUS[receipt.status] || { label: receipt.status, cls: '' };
    return (
        <div className={`receipt ${st.cls}`}>
            <div className="receipt-head">
                <b>{CHANNEL_LABELS[receipt.channel]}</b>
                <span className="receipt-status">{st.label}</span>
                {receipt.status === 'failed' && (
                    <button className="btn tiny" disabled={busy} onClick={() => onRetry(receipt)}>
                        {busy ? '重试中…' : '单独重试'}
                    </button>
                )}
            </div>
            <div className="muted small">
                尝试 {receipt.attempts} 次
                {receipt.delivered_at ? ` · 回执 ${fmt(receipt.delivered_at)}` : ''}
                {receipt.status === 'pending' && receipt.deliver_after ? ` · 预计 ${fmt(receipt.deliver_after)} 到达` : ''}
            </div>
            {receipt.last_error && <div className="field-error">{receipt.last_error}</div>}
        </div>
    );
}

export default function ChannelsPage({ store, showToast }) {
    const [states, setStates] = useState([]);
    const [publications, setPublications] = useState([]);
    const [tasks, setTasks] = useState([]);
    const [retrying, setRetrying] = useState(null);
    const [sim, setSim] = useState({ channel: 'delivery', mode: 'fail_once', delay_seconds: 45 });

    const load = () => {
        api.get(`/api/stores/${store.id}/channel-states`).then(setStates).catch(() => {});
        api.get(`/api/stores/${store.id}/publications`).then(setPublications).catch(() => {});
        api.get(`/api/stores/${store.id}/restore-tasks`).then(setTasks).catch(() => {});
    };
    useEffect(() => { load(); }, [store.id]);

    const retry = async (receipt) => {
        setRetrying(receipt.id);
        try {
            const r = await api.post(`/api/publication-channels/${receipt.id}/retry`);
            const msg = r.status === 'success' ? '重试成功，渠道已同步'
                : r.status === 'stale_ignored' ? '回执已过期（已有更新的发布），未改动渠道'
                : `重试结果：${r.status}`;
            showToast(msg, r.status === 'failed' ? 'error' : 'ok');
        } catch (e) {
            showToast(e.message, 'error');
        } finally {
            setRetrying(null);
            load();
        }
    };

    const inject = async () => {
        try {
            await api.post('/api/simulate/directive', { store_id: store.id, ...sim });
            showToast(sim.mode === 'clear' ? '已清除该渠道的模拟指令' : '已注入模拟指令，下次发布生效');
        } catch (e) {
            showToast(e.message, 'error');
        }
    };

    const runSim = async (url, label) => {
        try {
            const r = await api.post(url);
            showToast(`${label}：${JSON.stringify(r)}`);
            load();
        } catch (e) {
            showToast(e.message, 'error');
        }
    };

    return (
        <div>
            <section className="card">
                <h3>渠道当前对外展示（本地模拟的渠道端）</h3>
                <div className="channel-cards">
                    {states.map((s) => <ChannelStateCard key={s.channel} state={s} />)}
                </div>
            </section>

            <section className="card">
                <h3>发布记录与渠道回执</h3>
                {publications.length === 0 && <p className="muted">暂无发布记录。</p>}
                {publications.map((p) => (
                    <div className="pub" key={p.id}>
                        <div className="pub-head">
                            <span className="tag">#{p.epoch}</span>
                            <span className={`tag ${p.kind === 'restore' ? 'warn' : 'ok'}`}>
                                {p.kind === 'apply' ? '应用调整' : '恢复常规'}
                            </span>
                            <span>{p.summary}</span>
                            {p.adjustment && <span className="muted">（调整 #{p.adjustment.id} v{p.adjustment.version}）</span>}
                            <span className="muted">{fmt(p.created_at)}</span>
                        </div>
                        <div className="receipts">
                            {p.receipts.map((r) => (
                                <ReceiptChip key={r.id} receipt={r} onRetry={retry} busy={retrying === r.id} />
                            ))}
                        </div>
                    </div>
                ))}
            </section>

            <section className="card">
                <h3>到期恢复任务</h3>
                {tasks.length === 0 && <p className="muted">暂无恢复任务。</p>}
                {tasks.length > 0 && (
                    <table className="table">
                        <thead>
                            <tr><th>任务</th><th>调整版本</th><th>计划执行</th><th>状态</th><th>说明</th></tr>
                        </thead>
                        <tbody>
                            {tasks.map((t) => {
                                const st = TASK_STATUS[t.status] || { label: t.status, cls: '' };
                                return (
                                    <tr key={t.id}>
                                        <td>#{t.id}</td>
                                        <td>调整 #{t.adjustment_id} v{t.version}
                                            {t.adjustment_status ? <span className="muted">（当前 {t.adjustment_status}）</span> : null}
                                        </td>
                                        <td>{fmt(t.run_at)}</td>
                                        <td><span className={`status ${st.cls}`}>{st.label}</span></td>
                                        <td className="muted">{t.result_note || '—'}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </section>

            <section className="card sim">
                <h3>本地渠道模拟</h3>
                <p className="hint">用于验证：部分渠道失败后可单独补发；延迟回执晚到时不会覆盖更新的发布。</p>
                <div className="form-row">
                    <label>渠道
                        <select value={sim.channel} onChange={(e) => setSim({ ...sim, channel: e.target.value })}>
                            {CHANNELS.map((c) => <option key={c} value={c}>{CHANNEL_LABELS[c]}</option>)}
                        </select>
                    </label>
                    <label>行为
                        <select value={sim.mode} onChange={(e) => setSim({ ...sim, mode: e.target.value })}>
                            <option value="fail_once">下次发布失败一次</option>
                            <option value="fail_always">持续失败</option>
                            <option value="delay_once">下次回执延迟到达</option>
                            <option value="clear">清除模拟</option>
                        </select>
                    </label>
                    {sim.mode === 'delay_once' && (
                        <label>延迟秒数
                            <input type="number" min="1" max="3600" value={sim.delay_seconds}
                                onChange={(e) => setSim({ ...sim, delay_seconds: Number(e.target.value) })} />
                        </label>
                    )}
                    <button className="btn" onClick={inject}>注入</button>
                </div>
                <div className="form-row">
                    <button className="btn" onClick={() => runSim('/api/simulate/deliver-receipts', '投递到期回执')}>
                        投递到期回执
                    </button>
                    <button className="btn" onClick={() => runSim('/api/simulate/run-expiry', '到期恢复检查')}>
                        运行到期恢复检查
                    </button>
                </div>
            </section>
        </div>
    );
}
