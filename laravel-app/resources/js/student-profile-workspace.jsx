import React from 'react';
import { createRoot } from 'react-dom/client';
import '../css/student-profile-workspace.css';

function triggerClick(id) {
    const element = document.getElementById(id);
    if (element) {
        element.click();
    }
}

function openPasswordPanel() {
    if (typeof window.togglePasswordForm === 'function') {
        const container = document.getElementById('change-password-container');
        if (container && container.style.display === 'block') {
            container.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        window.togglePasswordForm();
        const nextContainer = document.getElementById('change-password-container');
        if (nextContainer) {
            nextContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
}

function StudentProfileWorkspace({
    studentName,
    roleLabel,
    note,
    chips = [],
    actionCards = [],
}) {
    const actionHandlers = {
        picture: () => triggerClick('file-input'),
        email: () => {
            if (typeof window.toggleEdit === 'function') {
                window.toggleEdit('email');
            }
            document.getElementById('email')?.focus();
        },
        contact: () => {
            if (typeof window.toggleEdit === 'function') {
                window.toggleEdit('contact_no');
            }
            document.getElementById('contact_no')?.focus();
        },
        password: () => openPasswordPanel(),
        save: () => {
            if (typeof window.saveChanges === 'function') {
                window.saveChanges();
            }
        },
    };

    return (
        <section className="student-profile-workspace">
            <div className="student-profile-workspace__lead">
                <div className="student-profile-workspace__eyebrow">{roleLabel}</div>
                <h2 className="student-profile-workspace__title">{studentName}</h2>
                <p className="student-profile-workspace__note">{note}</p>
                <div className="student-profile-workspace__chips">
                    {chips.map((chip) => (
                        <div className="student-profile-workspace__chip" key={`${chip.label}-${chip.value}`}>
                            <span className="student-profile-workspace__chip-label">{chip.label}</span>
                            <strong className="student-profile-workspace__chip-value">{chip.value}</strong>
                        </div>
                    ))}
                </div>
            </div>

            <div className="student-profile-workspace__actions">
                {actionCards.map((card) => (
                    <button
                        key={card.key}
                        type="button"
                        className="student-profile-workspace__action-card"
                        onClick={() => actionHandlers[card.key]?.()}
                    >
                        <span className="student-profile-workspace__action-title">{card.title}</span>
                        <span className="student-profile-workspace__action-desc">{card.description}</span>
                    </button>
                ))}
            </div>
        </section>
    );
}

function mountStudentProfileWorkspace(node) {
    const rawPayload = node.getAttribute('data-student-profile-workspace');
    if (!rawPayload) {
        return;
    }

    let payload = null;
    try {
        payload = JSON.parse(rawPayload);
    } catch (error) {
        console.error('Unable to parse student profile workspace payload.', error);
        return;
    }

    createRoot(node).render(<StudentProfileWorkspace {...payload} />);
}

document.querySelectorAll('[data-student-profile-workspace]').forEach(mountStudentProfileWorkspace);
