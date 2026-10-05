import React, { useEffect, useMemo, useState } from 'react';
import { api, ADJ_STATUS, CHANNEL_LABELS, CHANNELS, fmt } from '../api';

function toLocalInput(dt) {
    // "YYYY-MM-DD HH:MM:SS" -> "YYYY-MM-DDTHH:MM"
    return dt ? dt.slice(0, 16).replace(' ', 'T') : '';
}

function fromLocalInput(v) {
    return v ? v.replace('T', ' ') + (v.length === 16 ? ':00' : '') : v;
}

function SyncDots({ sync }) {
    if (!sync || Object.keys(sync).length === 0) return <span className="muted">未发布</span>;
    return (
        <span className="sync-dots">
            {CHANNELS.map((ch) => {
                const r = sync[ch];
                if (!r) return null;
                const ok = r.status === 'success';
                const bad = r.status === 'failed';
                const cls = ok ? 'ok' : bad ? 'bad' : 'wait';
                const label = r.status === 'success' ? '已同步'
                    : r.status === 'failed' ? `失败：${r.last_error || ''}`
                    : r.status === 'stale_ignored' ? '晚到回执已忽略' : '等待回执';
                return (
                    <span key={ch} className={`sync-dot ${cls}`} title={label}>
                        {CHANNEL_LABELS[ch]} {ok ? '✓' : bad ? '✗' : '…'}
                    </span>
                );
            })}
        </span>
    );
}

function AdjustmentForm({ title, initial, requireType, onSubmit, onCancel, submitLabel }) {
    const [form, setForm] = useState({
        type: initial?.type || 'closure',
        start_at: toLocalInput(initial?.start_at) || '',
        end_at: toLocalInput(initial?.end_at) || '',
        open_time: initial?.open_time || '08:00',
        close_time: initial?.close_time || '23:00',
        reason: initial?.reason || '',
        submitted_by: initial?.submitted_by || '店长小王',
    });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        try {
            await onSubmit({
                ...form,
                start_at: fromLocalInput(form.start_at),
                end_at: fromLocalInput(form.end_at),
            });
        } catch (err) {
            setErrors(err.validation || { _: [err.message] });
        } finally {
            setBusy(false);
        }
    };

    const fieldError = (k) => errors[k] ? <div className="field-error">{errors[k][0]}</div> : null;

    return (
        <form className="adj-form" onSubmit={submit}>
            <h4>{title}</h4>
            {errors._ && <div className="field-error">{errors._[0]}</div>}
            <div className="form-row">
                {requireType && (
                    <label>类型
                        <select value={form.type} onChange={set('type')}>
                            <option value="closure">临时停业</option>
                            <option value="extended">延长营业</option>
                        </select>
                    </label>
                )}
                <label>开始
                    <input type="datetime-local" value={form.start_at} onChange={set('start_at')} required />
                </label>
                <label>结束
                    <input type="datetime-local" value={form.end_at} onChange={set('end_at')} required />
                </label>
            </div>
            {(fieldError('start_at') || fieldError('end_at')) && <div className="field-error">{(errors.start_at || errors.end_at)[0]}</div>}
            {form.type === 'extended' && (
                <div className="form-row">
                    <label>对外开门
                        <input type="time" value={form.open_time} onChange={set('open_time')} required />
                    </label>
                    <label>对外打烊
                        <input type="time" value={form.close_time} onChange={set('close_time')} required />
                    </label>
                </div>
            )}
            {(fieldError('open_time') || fieldError('close_time')) && <div className="field-error">{(errors.open_time || errors.close_time)[0]}</div>}
            <div className="form-row">
                <label className="grow">原因
                    <input type="text" value={form.reason} onChange={set('reason')} placeholder="例如：设备检修 / 节假日客流高峰" required={requireType} />
                </label>
                <label>提交人
                    <input type="text" value={form.submitted_by} onChange={set('submitted_by')} required />
                </label>
            </div>
            {fieldError('reason')}
            <div className="form-actions">
                <button className="btn primary" type="submit" disabled={busy}>{busy ? '提交中…' : submitLabel}</button>
                {onCancel && <button className="btn ghost" type="button" onClick={onCancel}>取消</button>}
            </div>
        </form>
    );
}

export default function AdjustmentsPage({ store, showToast }) {
    const [items, setItems] = useState([]);
    const [showNew, setShowNew] = useState(false);
    const [reviseFor, setReviseFor] = useState(null);

    const load = () => api.get(`/api/stores/${store.id}/adjustments`).then(setItems).catch((e) => showToast(e.message, 'error'));
    useEffect(() => { setItems([]); setShowNew(false); setReviseFor(null); load(); }, [store.id]);

    const chains = useMemo(() => {
        const map = new Map();
        for (const a of items) {
            if (!map.has(a.root_id)) map.set(a.root_id, []);
            map.get(a.root_id).push(a);
        }
        return [...map.values()].map((versions) => versions.sort((x, y) => x.version - y.version));
    }, [items]);

    const act = async (fn, okMsg) => {
        try {
            await fn();
            showToast(okMsg);
            await load();
        } catch (e) {
            showToast(e.validation ? Object.values(e.validation)[0][0] : e.message, 'error');
        }
    };

    return (
        <div>
            <section className="card">
                <div className="card-head">
                    <h3>提交调整申请</h3>
                    <button className="btn primary" onClick={() => setShowNew(!showNew)}>
                        {showNew ? '收起' : '+ 新申请'}
                    </button>
                </div>
                {showNew && (
                    <AdjustmentForm
                        title="新调整申请（提交后进入总部审核）"
                        requireType
                        submitLabel="提交申请"
                        onCancel={() => setShowNew(false)}
                        onSubmit={async (form) => {
                            await api.post(`/api/stores/${store.id}/adjustments`, form);
                            setShowNew(false);
                            showToast('申请已提交，等待总部审核');
                            await load();
                        }}
                    />
                )}
            </section>

            {chains.length === 0 && <section className="card"><p className="muted">暂无调整申请。</p></section>}

            {chains.map((versions) => {
                const latest = versions[versions.length - 1];
                return (
                    <section className="card chain" key={versions[0].root_id}>
                        <div className="card-head">
                            <h3>
                                调整链 #{versions[0].root_id}
                                <span className="tag">{latest.type === 'closure' ? '临时停业' : '延长营业'}</span>
                                <span className="muted">共 {versions.length} 个版本</span>
                            </h3>
                        </div>
                        {versions.map((a) => {
                            const st = ADJ_STATUS[a.status] || { label: a.status, cls: '' };
                            const isCurrentApproved = a.status === 'approved';
                            return (
                                <div className={`version-row ${a.status}`} key={a.id}>
                                    <div className="version-main">
                                        <div className="version-title">
                                            <b>v{a.version}</b>
                                            <span className={`status ${st.cls}`}>{st.label}</span>
                                            <span className="muted">{fmt(a.start_at)} ~ {fmt(a.end_at)}</span>
                                            {a.type === 'extended' && <span className="muted">对外 {a.open_time}-{a.close_time}</span>}
                                        </div>
                                        <div className="muted">原因：{a.reason} · 提交人：{a.submitted_by}</div>
                                        {a.reviewed_by && (
                                            <div className="muted">审批：{a.reviewed_by} {fmt(a.reviewed_at)}{a.review_note ? ` · ${a.review_note}` : ''}</div>
                                        )}
                                        <div className="sync-line">
                                            <span className="muted">渠道同步：</span>
                                            {(a.status === 'approved' || a.status === 'expired' || a.status === 'superseded')
                                                ? <SyncDots sync={a.channel_sync} />
                                                : <span className="muted">批准后才会发布</span>}
                                        </div>
                                    </div>
                                    <div className="version-actions">
                                        {a.status === 'pending' && (
                                            <>
                                                <button className="btn ok" onClick={() => act(() => api.post(`/api/adjustments/${a.id}/approve`, { reviewed_by: '总部审核员' }), `v${a.version} 已批准并发布到各渠道`)}>总部批准</button>
                                                <button className="btn danger" onClick={() => act(() => api.post(`/api/adjustments/${a.id}/reject`, { reviewed_by: '总部审核员', review_note: '不符合安排' }), '已驳回')}>驳回</button>
                                            </>
                                        )}
                                        {isCurrentApproved && (
                                            <button className="btn" onClick={() => setReviseFor(reviseFor === a.id ? null : a.id)}>
                                                延期 / 提前结束
                                            </button>
                                        )}
                                    </div>
                                    {reviseFor === a.id && (
                                        <AdjustmentForm
                                            title={`基于 v${a.version} 生成新版本（原版本保留，新版本需总部批准）`}
                                            initial={a}
                                            submitLabel={`生成 v${a.version + 1} 并送审`}
                                            onCancel={() => setReviseFor(null)}
                                            onSubmit={async (form) => {
                                                await api.post(`/api/adjustments/${a.id}/revise`, form);
                                                setReviseFor(null);
                                                showToast(`已生成 v${a.version + 1}，等待总部批准`);
                                                await load();
                                            }}
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </section>
                );
            })}
        </div>
    );
}
