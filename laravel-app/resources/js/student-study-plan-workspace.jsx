import React from 'react';
import { createRoot } from 'react-dom/client';
import '../css/student-study-plan-workspace.css';

function dispatchStudyPlanAction(action) {
    document.dispatchEvent(new CustomEvent('student-study-plan:action', { detail: { action } }));
}

function StudentStudyPlanWorkspace({
    title,
    note,
    stats = [],
    insights = [],
}) {
    return (
        <section className="student-study-plan-workspace">
            <div className="student-study-plan-workspace__copy">
                <div className="student-study-plan-workspace__eyebrow">Planning command deck</div>
                <h2 className="student-study-plan-workspace__title">{title}</h2>
                <p className="student-study-plan-workspace__note">{note}</p>

                <div className="student-study-plan-workspace__actions">
                    <button type="button" onClick={() => dispatchStudyPlanAction('print')}>
                        Print Study Plan
                    </button>
                    <button type="button" onClick={() => dispatchStudyPlanAction('overview')}>
                        Open A.Y. Overview
                    </button>
                    <button type="button" onClick={() => dispatchStudyPlanAction('progress')}>
                        Jump to Progress
                    </button>
                </div>
            </div>

            <div className="student-study-plan-workspace__side">
                <div className="student-study-plan-workspace__stats">
                    {stats.map((stat) => (
                        <div className="student-study-plan-workspace__stat" key={`${stat.label}-${stat.value}`}>
                            <span className="student-study-plan-workspace__stat-label">{stat.label}</span>
                            <strong className="student-study-plan-workspace__stat-value">{stat.value}</strong>
                        </div>
                    ))}
                </div>

                {insights.length > 0 ? (
                    <div className="student-study-plan-workspace__insights">
                        {insights.map((item) => (
                            <div className="student-study-plan-workspace__insight" key={item.title}>
                                <span className="student-study-plan-workspace__insight-title">{item.title}</span>
                                <span className="student-study-plan-workspace__insight-value">{item.value}</span>
                            </div>
                        ))}
                    </div>
                ) : null}
            </div>
        </section>
    );
}

function mountStudentStudyPlanWorkspace(node) {
    const rawPayload = node.getAttribute('data-student-study-plan-workspace');
    if (!rawPayload) {
        return;
    }

    let payload = null;
    try {
        payload = JSON.parse(rawPayload);
    } catch (error) {
        console.error('Unable to parse student study plan workspace payload.', error);
        return;
    }

    createRoot(node).render(<StudentStudyPlanWorkspace {...payload} />);
}

document.querySelectorAll('[data-student-study-plan-workspace]').forEach(mountStudentStudyPlanWorkspace);
