---
name: Graphite Precision
colors:
  surface: '#f8faf3'
  surface-dim: '#d9dbd4'
  surface-bright: '#f8faf3'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f3f4ee'
  surface-container: '#edefe8'
  surface-container-high: '#e7e9e2'
  surface-container-highest: '#e1e3dd'
  on-surface: '#191c19'
  on-surface-variant: '#454843'
  inverse-surface: '#2e312d'
  inverse-on-surface: '#f0f1eb'
  outline: '#757872'
  outline-variant: '#c5c7c1'
  surface-tint: '#5c5f5a'
  primary: '#000000'
  on-primary: '#ffffff'
  primary-container: '#191c18'
  on-primary-container: '#82857f'
  inverse-primary: '#c5c7c1'
  secondary: '#4e6700'
  on-secondary: '#ffffff'
  secondary-container: '#c0f03f'
  on-secondary-container: '#516b00'
  tertiary: '#000000'
  on-tertiary: '#ffffff'
  tertiary-container: '#171e09'
  on-tertiary-container: '#7f876a'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#e1e3dc'
  primary-fixed-dim: '#c5c7c1'
  on-primary-fixed: '#191c18'
  on-primary-fixed-variant: '#454843'
  secondary-fixed: '#c3f341'
  secondary-fixed-dim: '#a8d622'
  on-secondary-fixed: '#151f00'
  on-secondary-fixed-variant: '#3a4d00'
  tertiary-fixed: '#dde6c4'
  tertiary-fixed-dim: '#c1caa9'
  on-tertiary-fixed: '#171e09'
  on-tertiary-fixed-variant: '#424a31'
  background: '#f8faf3'
  on-background: '#191c19'
  surface-variant: '#e1e3dd'
typography:
  headline-lg:
    fontFamily: Hanken Grotesk
    fontSize: 40px
    fontWeight: '600'
    lineHeight: 48px
    letterSpacing: -0.02em
  headline-lg-mobile:
    fontFamily: Hanken Grotesk
    fontSize: 32px
    fontWeight: '600'
    lineHeight: 38px
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Hanken Grotesk
    fontSize: 24px
    fontWeight: '500'
    lineHeight: 32px
  body-lg:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: '400'
    lineHeight: 28px
  body-md:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  label-md:
    fontFamily: JetBrains Mono
    fontSize: 14px
    fontWeight: '500'
    lineHeight: 20px
    letterSpacing: 0.02em
  button-text:
    fontFamily: Hanken Grotesk
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 20px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 4px
  xs: 8px
  sm: 16px
  md: 24px
  lg: 48px
  xl: 80px
  gutter: 24px
  margin-mobile: 16px
  margin-desktop: 64px
---

## Brand & Style
This design system embodies a high-end, technical aesthetic centered around precision and industrial elegance. The brand personality is authoritative yet restrained, targeting professionals who value clarity and a premium feel. 

The design style is **Corporate / Modern** with a lean toward **Minimalism**. It prioritizes a flat, high-contrast interface that avoids excessive ornamentation like gradients or shadows. Instead, it relies on a sophisticated "Graphite" palette punctuated by high-visibility functional accents. The emotional response is one of confidence, stability, and surgical focus.

## Colors
The palette is architectural, utilizing a deep "Graphite" (#191C18) as the primary anchor. The "Signal Lime" (#B9E937) is the system's vital sign—used exclusively for active states, navigational highlights, and functional indicators to ensure immediate cognitive recognition.

- **Primary Graphite**: Used for high-emphasis actions and core branding.
- **Signal Lime**: Reserved for focus rings, toggles, and active navigation.
- **Soft Lime**: Utilized for subtle background highlighting in selected states (e.g., list selection).
- **Functional Semantics**: Destructive actions use a muted brick red (#D14A3A), while success states utilize a deep forest green (#16775B).

## Typography
The typographic hierarchy blends the contemporary sharpness of Hanken Grotesk for headings and buttons with the utilitarian clarity of Inter for body copy. For technical data or small metadata labels, JetBrains Mono provides a developer-friendly, precise feel.

Headlines use tighter letter spacing and medium-to-semibold weights to maintain a "Graphite" density. Large headlines scale down for mobile devices to maintain readability without excessive wrapping.

## Layout & Spacing
The layout follows a strict 8px grid system to ensure mathematical alignment across all viewports.

- **Desktop**: A 12-column fluid grid with 24px gutters and 64px side margins. 
- **Tablet**: An 8-column grid with 24px gutters and 32px margins.
- **Mobile**: A 4-column grid with 16px gutters and 16px side margins.

Horizontal spacing for components should favor generous padding to emphasize the minimalist aesthetic. Containers should use `lg` spacing for vertical separation to allow the typography to breathe.

## Elevation & Depth
This design system rejects traditional shadows and gradients in favor of **Tonal Layers** and **Low-contrast Outlines**. 

Depth is communicated through color-blocking and stroke density. Surfaces are flat. Interactive elements are distinguished by their solid background fills (Graphite) or 1px structural borders (#D8DAD4). When an element requires focus, a 2px "Signal Lime" ring is applied with a 2px offset, creating a sharp, digital "glow" effect without using blurs.

## Shapes
The shape language is controlled and systematic. A standard 0.5rem (8px) radius is used for most containers and input fields, providing a modern but grounded appearance. Buttons specifically use a slightly more pronounced 12px radius to differentiate them as the primary touchpoints of the UI. Higher-level containers like cards may use `rounded-xl` (1.5rem) to create a clear framing effect.

## Components

### Buttons
Buttons are the primary interactive elements and must be 48-52px in height.
- **Primary**: Solid #191C18 background with #FFFFFF text. 12px border-radius.
- **Secondary**: #FFFFFF background with a 1px #D8DAD4 border and #252824 text. 12px border-radius.
- **Tertiary**: Transparent background with #3E433B text. No border.
- **States**: Use a 2px #B9E937 focus ring for keyboard navigation.

### Selected States & Toggles
- **Selected Items**: Use a #EFF8D5 (Soft Lime) background with dark text (#191C18).
- **Toggles/Active Navigation**: Use #B9E937 (Signal Lime) for the "On" state or active indicator line.

### Input Fields
Inputs should match the secondary button style: 48px height, #FFFFFF background, 1px #D8DAD4 border, and 8px radius. Text should be `body-md`. Focus state switches the border to #191C18 or adds the Signal Lime focus ring.

### Cards
Flat surfaces with a 1px #D8DAD4 border. No shadow. Use #FFFFFF for the surface and `rounded-lg` or `rounded-xl` for the corners depending on the container hierarchy.

### Chips
Small-scale labels using #EFF8D5 background and #3E433B text for a subtle, premium organizational tool.