import React, { useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '../css/student-checklist-workspace.css';

function emitChecklistAction(action, detail = {}) {
    document.dispatchEvent(new CustomEvent('student-checklist:action', { detail: { action, ...detail } }));
}

function StudentChecklistWorkspace({
    title,
    note,
    programLabel,
    stats = [],
    totalPages = 1,
    initialPage = 1,
}) {
    const [currentPage, setCurrentPage] = useState(initialPage);

    useEffect(() => {
        const listener = (event) => {
            const nextPage = Number(event?.detail?.currentPage || 1);
            if (!Number.isNaN(nextPage)) {
                setCurrentPage(nextPage);
            }
        };

        document.addEventListener('student-checklist:page-change', listener);
        return () => document.removeEventListener('student-checklist:page-change', listener);
    }, []);

    const pageLabel = useMemo(() => `Page ${currentPage} of ${totalPages}`, [currentPage, totalPages]);

    return (
        <section className="student-checklist-workspace">
            <div className="student-checklist-workspace__copy">
                <div className="student-checklist-workspace__eyebrow">Checklist command deck</div>
                <h2 className="student-checklist-workspace__title">{title}</h2>
                <p className="student-checklist-workspace__note">{note}</p>
                <div className="student-checklist-workspace__program">
                    <span>Program</span>
                    <strong>{programLabel}</strong>
                </div>
            </div>

            <div className="student-checklist-workspace__aside">
                <div className="student-checklist-workspace__stats">
                    {stats.map((stat) => (
                        <div className="student-checklist-workspace__stat" key={`${stat.label}-${stat.value}`}>
                            <span className="student-checklist-workspace__stat-label">{stat.label}</span>
                            <strong className="student-checklist-workspace__stat-value">{stat.value}</strong>
                        </div>
                    ))}
                </div>

                <div className="student-checklist-workspace__controls">
                    <div className="student-checklist-workspace__page-label">{pageLabel}</div>
                    <div className="student-checklist-workspace__buttons">
                        <button
                            type="button"
                            onClick={() => emitChecklistAction('prev-page')}
                            disabled={currentPage <= 1}
                        >
                            Previous Page
                        </button>
                        <button
                            type="button"
                            onClick={() => emitChecklistAction('next-page')}
                            disabled={currentPage >= totalPages}
                        >
                            Next Page
                        </button>
                        <button type="button" onClick={() => emitChecklistAction('print')}>
                            Print Checklist
                        </button>
                        <button type="button" onClick={() => emitChecklistAction('archive')}>
                            Archived Checklist
                        </button>
                    </div>
                </div>
            </div>
        </section>
    );
}

function mountStudentChecklistWorkspace(node) {
    const rawPayload = node.getAttribute('data-student-checklist-workspace');
    if (!rawPayload) {
        return;
    }

    let payload = null;
    try {
        payload = JSON.parse(rawPayload);
    } catch (error) {
        console.error('Unable to parse student checklist workspace payload.', error);
        return;
    }

    createRoot(node).render(<StudentChecklistWorkspace {...payload} />);
}

document.querySelectorAll('[data-student-checklist-workspace]').forEach(mountStudentChecklistWorkspace);
