import React, { useEffect, useMemo, useState } from 'react';
import { api, fmt } from '../api';

const WEEKDAYS = ['一', '二', '三', '四', '五', '六', '日'];

function pad(n) { return String(n).padStart(2, '0'); }
function ymd(d) { return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; }

/** 以周一为起点的 6 周网格 */
function monthCells(year, month) {
    const first = new Date(year, month, 1);
    const offset = (first.getDay() + 6) % 7;
    const start = new Date(year, month, 1 - offset);
    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        return d;
    });
}

export default function CalendarPage({ store }) {
    const today = new Date();
    const [cursor, setCursor] = useState({ year: today.getFullYear(), month: today.getMonth() });
    const [data, setData] = useState({ days: [], drafts: [] });

    const cells = useMemo(() => monthCells(cursor.year, cursor.month), [cursor]);
    const from = ymd(cells[0]);
    const to = ymd(cells[41]);

    useEffect(() => {
        api.get(`/api/stores/${store.id}/calendar?from=${from}&to=${to}`)
            .then(setData)
            .catch(() => {});
    }, [store.id, from, to]);

    const byDate = useMemo(() => {
        const map = {};
        for (const d of data.days) map[d.date] = d;
        return map;
    }, [data]);

    const shift = (delta) => {
        const d = new Date(cursor.year, cursor.month + delta, 1);
        setCursor({ year: d.getFullYear(), month: d.getMonth() });
    };

    return (
        <div className="calendar-layout">
            <section className="card">
                <div className="cal-head">
                    <button className="btn ghost" onClick={() => shift(-1)}>‹ 上月</button>
                    <h2>{cursor.year} 年 {cursor.month + 1} 月</h2>
                    <button className="btn ghost" onClick={() => shift(1)}>下月 ›</button>
                </div>
                <p className="hint">日历只显示<b>已批准并对外生效</b>的营业时间；审核中的草稿不会出现在这里。</p>
                <div className="cal-grid">
                    {WEEKDAYS.map((w) => <div key={w} className="cal-weekday">{w}</div>)}
                    {cells.map((d) => {
                        const key = ymd(d);
                        const day = byDate[key];
                        const outside = d.getMonth() !== cursor.month;
                        const isToday = key === ymd(new Date());
                        return (
                            <div
                                key={key}
                                className={[
                                    'cal-cell',
                                    outside ? 'outside' : '',
                                    isToday ? 'today' : '',
                                    day?.is_closed ? 'closed' : '',
                                    day?.source === 'adjustment' ? 'adjusted' : '',
                                ].join(' ')}
                            >
                                <div className="cal-date">{d.getDate()}</div>
                                {day ? (
                                    day.is_closed ? (
                                        <div className="cal-hours off">
                                            {day.source === 'adjustment' ? '临时停业' : '休息'}
                                        </div>
                                    ) : (
                                        <div className="cal-hours">{day.open} - {day.close}</div>
                                    )
                                ) : <div className="cal-hours">…</div>}
                                {day?.adjustment && (
                                    <div className={`cal-badge ${day.adjustment.type}`}>
                                        {day.adjustment.type === 'closure' ? '停业' : '延时'} v{day.adjustment.version}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
                <div className="legend">
                    <span><i className="dot base" /> 常规时间</span>
                    <span><i className="dot adj" /> 临时调整（已批准）</span>
                    <span><i className="dot closed" /> 停业 / 休息</span>
                </div>
            </section>

            <aside className="card side">
                <h3>审核中的草稿 <span className="tag warn">不对外生效</span></h3>
                {data.drafts.length === 0 && <p className="muted">当前没有待审核的调整申请。</p>}
                {data.drafts.map((d) => (
                    <div key={d.id} className="draft-item">
                        <div className="draft-title">
                            #{d.id} v{d.version} {d.type === 'closure' ? '临时停业' : '延长营业'}
                        </div>
                        <div className="muted">{fmt(d.start_at)} ~ {fmt(d.end_at)}</div>
                        {d.type === 'extended' && <div className="muted">对外 {d.open_time} - {d.close_time}</div>}
                        <div className="muted">原因：{d.reason}</div>
                    </div>
                ))}
            </aside>
        </div>
    );
}
