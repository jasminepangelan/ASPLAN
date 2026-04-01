import React from 'react';
import { createRoot } from 'react-dom/client';
import '../css/adviser-page-workspace.css';

function handleAction(action) {
    switch (action.type) {
        case 'print':
            window.print();
            break;
        case 'scroll':
            if (action.selector) {
                const target = document.querySelector(action.selector);
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
            break;
        default:
            break;
    }
}

function AdviserPreenrollWorkspace({ heading, description, actions = [], notes = [] }) {
    return (
        <section className="adviser-workspace adviser-workspace--warm">
            <div className="adviser-workspace__header">
                <div>
                    <div className="adviser-workspace__eyebrow">Pre-Enrollment Tools</div>
                    <h2 className="adviser-workspace__title">{heading}</h2>
                </div>
                <p className="adviser-workspace__description">{description}</p>
            </div>
            <div className="adviser-workspace__actions">
                {actions.map((action) =>
                    action.href ? (
                        <a key={action.key} className="adviser-workspace__action" href={action.href}>
                            <span className="adviser-workspace__action-title">{action.title}</span>
                            <span className="adviser-workspace__action-description">{action.description}</span>
                        </a>
                    ) : (
                        <button
                            key={action.key}
                            type="button"
                            className="adviser-workspace__action adviser-workspace__action--button"
                            onClick={() => handleAction(action)}
                        >
                            <span className="adviser-workspace__action-title">{action.title}</span>
                            <span className="adviser-workspace__action-description">{action.description}</span>
                        </button>
                    ),
                )}
            </div>
            {notes.length ? (
                <div className="adviser-workspace__notes">
                    {notes.map((note) => (
                        <div className="adviser-workspace__note" key={note}>
                            {note}
                        </div>
                    ))}
                </div>
            ) : null}
        </section>
    );
}

function mount(node) {
    const rawPayload = node.getAttribute('data-adviser-preenroll-workspace');
    if (!rawPayload) {
        return;
    }

    try {
        const payload = JSON.parse(rawPayload);
        createRoot(node).render(<AdviserPreenrollWorkspace {...payload} />);
    } catch (error) {
        console.error('Unable to parse adviser pre-enrollment workspace payload.', error);
    }
}

document.querySelectorAll('[data-adviser-preenroll-workspace]').forEach(mount);
