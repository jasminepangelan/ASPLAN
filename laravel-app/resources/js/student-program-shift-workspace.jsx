import React from 'react';
import { createRoot } from 'react-dom/client';
import '../css/student-program-shift-workspace.css';

function dispatchProgramShiftAction(action) {
    document.dispatchEvent(new CustomEvent('student-program-shift:action', { detail: { action } }));
}

function StudentProgramShiftWorkspace({
    title,
    note,
    stats = [],
    reminders = [],
}) {
    return (
        <section className="student-program-shift-workspace">
            <div className="student-program-shift-workspace__copy">
                <div className="student-program-shift-workspace__eyebrow">Shift request command deck</div>
                <h2 className="student-program-shift-workspace__title">{title}</h2>
                <p className="student-program-shift-workspace__note">{note}</p>

                <div className="student-program-shift-workspace__actions">
                    <button type="button" onClick={() => dispatchProgramShiftAction('request')}>
                        Jump to Request Form
                    </button>
                    <button type="button" onClick={() => dispatchProgramShiftAction('history')}>
                        View Request History
                    </button>
                    <button type="button" onClick={() => dispatchProgramShiftAction('destination')}>
                        Choose Destination Program
                    </button>
                </div>
            </div>

            <div className="student-program-shift-workspace__side">
                <div className="student-program-shift-workspace__stats">
                    {stats.map((stat) => (
                        <div className="student-program-shift-workspace__stat" key={`${stat.label}-${stat.value}`}>
                            <span className="student-program-shift-workspace__stat-label">{stat.label}</span>
                            <strong className="student-program-shift-workspace__stat-value">{stat.value}</strong>
                        </div>
                    ))}
                </div>

                {reminders.length > 0 ? (
                    <div className="student-program-shift-workspace__reminders">
                        {reminders.map((item) => (
                            <div className="student-program-shift-workspace__reminder" key={item}>
                                {item}
                            </div>
                        ))}
                    </div>
                ) : null}
            </div>
        </section>
    );
}

function mountStudentProgramShiftWorkspace(node) {
    const rawPayload = node.getAttribute('data-student-program-shift-workspace');
    if (!rawPayload) {
        return;
    }

    let payload = null;
    try {
        payload = JSON.parse(rawPayload);
    } catch (error) {
        console.error('Unable to parse student program shift workspace payload.', error);
        return;
    }

    createRoot(node).render(<StudentProgramShiftWorkspace {...payload} />);
}

document.querySelectorAll('[data-student-program-shift-workspace]').forEach(mountStudentProgramShiftWorkspace);
