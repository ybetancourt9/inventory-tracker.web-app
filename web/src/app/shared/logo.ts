import { Component, input } from '@angular/core';

/**
 * Brand mark: a carton drawn as three faces meeting at the front edge.
 *
 * Strokes are geometric rather than detailed so the shape survives at favicon
 * size, where the same drawing is reused.
 */
@Component({
  selector: 'app-logo',
  template: `
    <svg
      class="logo"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="1.75"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
      focusable="false"
      [style.width]="size()"
      [style.height]="size()"
    >
      <path d="M3.5 7.4 12 2.8l8.5 4.6v9.2L12 21.2 3.5 16.6Z" />
      <path d="M3.5 7.4 12 12l8.5-4.6" />
      <path d="M12 12v9.2" />
    </svg>
  `,
  styles: `
    .logo {
      display: block;
    }
  `,
})
export class Logo {
  readonly size = input('1.5rem');
}
