# The Figma Redesign Prompt: ASPLAN Login UI

**Role & Context:** 
Act as a Senior UX/UI Designer. Redesign the "ASPLAN (Automated Study Plan Generator)" login screen to feel premium, modern, and trustworthy. The current design feels dated due to the heavy green tint, flat containers, and lack of visual hierarchy. We want to move towards a sleek, "Glassmorphism" or "Modern Card" aesthetic that is visually striking and highly usable.

---

## Phase 1: Foundation & Background
**Goal:** Create a deep, immersive background that doesn't overpower the foreground elements.
*   **Action 1:** Keep the university campus image but remove the flat, heavy green overlay.
*   **Action 2:** Apply a dark, rich gradient overlay (e.g., from deep forest green `#0A1F11` to dark charcoal `#111111`) set to 70-80% opacity. This will make the background look premium and ensure the foreground text and cards are highly readable.
*   **Action 3:** Add a subtle radial glow (a soft, vibrant emerald green) in the background behind where the main login container will sit to draw the eye to the center.

## Phase 2: Layout & Structural Grid
**Goal:** Improve balance and alignment using Figma's Auto Layout.
*   **Action 1:** Move away from two floating, disconnected boxes. Instead, create a single, unified floating center panel (e.g., 1000px wide, 600px high) with beautifully rounded corners (e.g., 24px radius).
*   **Action 2:** Split this new panel into two distinct columns (50/50 split):
    *   **Left Column:** For Branding and Welcome text.
    *   **Right Column:** For the Login Form.

## Phase 3: Left Column - Branding (The "Wow" Factor)
**Goal:** Make the brand feel established and modern.
*   **Action 1 (Style):** Make this left column a solid, deep premium green (or use a high-quality blurred photo background specifically for this half).
*   **Action 2 (Typography):** Upgrade the font to a modern sans-serif like **Inter**, **Outfit**, or **Plus Jakarta Sans**.
*   **Action 3 (Logo):** Center the university logo. 
*   **Action 4 (Text):** Replace the flat yellowish text. Make "ASPLAN" pure white, bold (ExtraBold or Black weight), and large. Make the subtitle "Automated Study Plan Generator" a soft, light grey/green, using a medium weight. 
*   **Action 5:** Add a subtle, decorative element, like a thin, elegant divider line or a soft glow behind the text.

## Phase 4: Right Column - The Login Form
**Goal:** Create a clean, frictionless, and modern input experience.
*   **Action 1 (Container):** Use a **Glassmorphism effect** for this right column. Give it a white background with 10% opacity, a backdrop blur of `20px`, and a subtle `1px` solid white border at 20% opacity. 
*   **Action 2 (Header):** Add a clean, dark greeting at the top, like "Welcome Back" (Large, Bold) and "Please enter your details to sign in" (Small, subtle grey).
*   **Action 3 (Input Fields):** 
    *   Remove the harsh borders. Use a soft, light grey background (`#F9FAFB` with 50% opacity) for the input fields.
    *   Add a subtle `1px` border that highlights (e.g., brand green) when the user clicks it (focus state).
    *   Add highly legible placeholder text and integrate crisp, minimal icons inside the fields (a 'User' icon for the first field, a 'Lock' icon for the password).
*   **Action 4 (Links):** Align "Remember me" (checkbox) to the left and "Forgot password?" to the right on the same line, using a small, clean font.

## Phase 5: Calls to Action & Final Polish
**Goal:** Make the primary action irresistible and clean up secondary actions.
*   **Action 1 (Primary Button):** Redesign the "LOG IN" button. Give it a vibrant, premium green gradient (e.g., `#10B981` to `#047857`). Add a soft drop shadow colored in the same green to make it glow. Round the corners to match the input fields (e.g., 8px or fully pill-shaped).
*   **Action 2 (Secondary Button):** Move the "Create an Account" link below the login button, styled as: "Don't have an account? **Sign up**" (with "Sign up" in the bold brand green).
*   **Action 3 (Cleanup):** Remove the "About Us" button from inside the login form. Place it as a clean, simple text link at the very bottom footer of the entire screen, outside the main card.
*   **Action 4 (Spacing):** Ensure consistent padding. Use Figma's Auto Layout with a gap of `16px` or `24px` between form elements so the design breathes.

---

### How to use this in Figma:
1. **If doing it manually:** Treat each phase as a checklist. Start by setting up your frame and background (Phase 1), then build the containers (Phase 2), and work your way down to the micro-details.
2. **If using a Figma AI Plugin (like Wireframe Designer, Musho, or similar):** You can paste the entire block above directly into their prompt box. The structured goals and specific styling instructions (like "Glassmorphism," "Inter font," and specific hex concepts) yield much better AI-generated layouts.
