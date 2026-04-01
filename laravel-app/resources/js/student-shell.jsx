import React from 'react';
import { createRoot } from 'react-dom/client';
import '../css/student-shell.css';

const DEFAULT_LINKS = [
    { label: 'Profile', href: 'acc_mng.php', key: 'profile' },
    { label: 'Checklist', href: 'checklist_stud.php', key: 'checklist' },
    { label: 'Study Plan', href: 'study_plan.php', key: 'study-plan' },
    { label: 'Program Shift', href: 'program_shift_request.php', key: 'program-shift' },
];

function StudentWorkspaceShell({
    title,
    description,
    accent = 'emerald',
    pageKey = '',
    stats = [],
    links = DEFAULT_LINKS,
}) {
    return (
        <section className={`student-shell student-shell--${accent}`}>
            <div className="student-shell__hero">
                <div className="student-shell__eyebrow">Student Workspace</div>
                <h1 className="student-shell__title">{title}</h1>
                <p className="student-shell__description">{description}</p>
            </div>

            <div className="student-shell__panel">
                <div className="student-shell__nav" aria-label="Student modules">
                    {links.map((link) => {
                        const isActive = link.key === pageKey;

                        return (
                            <a
                                key={link.key}
                                className={`student-shell__nav-link${isActive ? ' is-active' : ''}`}
                                href={link.href}
                                aria-current={isActive ? 'page' : undefined}
                            >
                                {link.label}
                            </a>
                        );
                    })}
                </div>

                {stats.length > 0 ? (
                    <div className="student-shell__stats">
                        {stats.map((stat) => (
                            <div className="student-shell__stat" key={`${stat.label}-${stat.value}`}>
                                <div className="student-shell__stat-label">{stat.label}</div>
                                <div className="student-shell__stat-value">{stat.value}</div>
                            </div>
                        ))}
                    </div>
                ) : null}
            </div>
        </section>
    );
}

function mountStudentShell(node) {
    const rawPayload = node.getAttribute('data-student-shell');
    if (!rawPayload) {
        return;
    }

    let payload = null;
    try {
        payload = JSON.parse(rawPayload);
    } catch (error) {
        console.error('Unable to parse student shell payload.', error);
        return;
    }

    createRoot(node).render(<StudentWorkspaceShell {...payload} />);
}

document.querySelectorAll('[data-student-shell]').forEach(mountStudentShell);
