import React from 'react';
import { createRoot } from 'react-dom/client';
import '../css/adviser-shell.css';

const DEFAULT_LINKS = [
    { label: 'Dashboard', href: 'index.php', key: 'dashboard' },
    { label: 'Pending Accounts', href: 'pending_accounts.php', key: 'pending-accounts' },
    { label: 'Student List', href: 'checklist_eval.php', key: 'student-list' },
    { label: 'Study Plan List', href: 'study_plan_list.php', key: 'study-plan-list' },
    { label: 'Program Shift', href: 'program_shift_requests.php', key: 'program-shift' },
];

function AdviserWorkspaceShell({
    title,
    description,
    accent = 'evergreen',
    pageKey = '',
    stats = [],
    links = DEFAULT_LINKS,
}) {
    return (
        <section className={`adviser-shell adviser-shell--${accent}`}>
            <div className="adviser-shell__hero">
                <div className="adviser-shell__eyebrow">Adviser Workspace</div>
                <h1 className="adviser-shell__title">{title}</h1>
                <p className="adviser-shell__description">{description}</p>
            </div>

            <div className="adviser-shell__panel">
                <div className="adviser-shell__nav" aria-label="Adviser modules">
                    {links.map((link) => {
                        const isActive = link.key === pageKey;

                        return (
                            <a
                                key={link.key}
                                className={`adviser-shell__nav-link${isActive ? ' is-active' : ''}`}
                                href={link.href}
                                aria-current={isActive ? 'page' : undefined}
                            >
                                {link.label}
                            </a>
                        );
                    })}
                </div>

                {stats.length > 0 ? (
                    <div className="adviser-shell__stats">
                        {stats.map((stat) => (
                            <div className="adviser-shell__stat" key={`${stat.label}-${stat.value}`}>
                                <div className="adviser-shell__stat-label">{stat.label}</div>
                                <div className="adviser-shell__stat-value">{stat.value}</div>
                            </div>
                        ))}
                    </div>
                ) : null}
            </div>
        </section>
    );
}

function mountAdviserShell(node) {
    const rawPayload = node.getAttribute('data-adviser-shell');
    if (!rawPayload) {
        return;
    }

    let payload = null;
    try {
        payload = JSON.parse(rawPayload);
    } catch (error) {
        console.error('Unable to parse adviser shell payload.', error);
        return;
    }

    createRoot(node).render(<AdviserWorkspaceShell {...payload} />);
}

document.querySelectorAll('[data-adviser-shell]').forEach(mountAdviserShell);
