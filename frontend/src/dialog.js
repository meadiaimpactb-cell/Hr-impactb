import './dialog.css'

// Branded, RTL, theme-aware dialogs used everywhere instead of the browser's alert/confirm/prompt.
// All functions are async: `if (!(await confirmBox('…'))) return`.
// SweetAlert2 is loaded on first use (it touches `document` at import time, which breaks server-side render checks)
let mixin = null
async function swal() {
  if (!mixin) {
    const Swal = (await import('sweetalert2')).default
    await import('sweetalert2/dist/sweetalert2.min.css')
    mixin = Swal.mixin(BASE)
  }
  return mixin
}
const BASE = ({
  customClass: { popup: 'hc-swal', title: 'hc-swal-title', htmlContainer: 'hc-swal-text', confirmButton: 'btn pri', cancelButton: 'btn sec', denyButton: 'btn dan', actions: 'hc-swal-actions', input: 'in hc-swal-input', validationMessage: 'hc-swal-err' },
  buttonsStyling: false,
  reverseButtons: false, // in RTL the first button (confirm) sits on the right, where the eye starts
  focusCancel: false,
  showClass: { popup: 'hc-swal-in' },
  hideClass: { popup: 'hc-swal-out' },
  allowOutsideClick: false, // like every dialog in the system: close only through a button
  allowEscapeKey: true,
})

const DANGER = /حذف|إلغاء|سحب|إنهاء|تراجع|رفض|إيقاف|إعادة تعيين|تجميع من جديد/

/** Yes/no question. Destructive wording turns the confirm button red and focuses Cancel. */
export async function confirmBox(text, { title, confirmText = 'تأكيد', cancelText = 'إلغاء', danger } = {}) {
  const isDanger = danger ?? DANGER.test(text + (title || ''))
  const r = await (await swal()).fire({
    icon: isDanger ? 'warning' : 'question',
    iconColor: isDanger ? 'var(--bad)' : 'var(--deep)',
    title: title || (isDanger ? 'تأكيد الإجراء' : 'تأكيد'),
    text,
    showCancelButton: true,
    confirmButtonText: confirmText,
    cancelButtonText: cancelText,
    focusCancel: isDanger,
    customClass: { ...BASE.customClass, confirmButton: isDanger ? 'btn dan' : 'btn pri' },
  })
  return r.isConfirmed
}

/** Information / success / error message. */
export async function alertBox(text, { title, icon = 'info', button = 'حسنًا' } = {}) {
  await (await swal()).fire({ icon, iconColor: { success: 'var(--ok)', error: 'var(--bad)', warning: 'var(--warn)' }[icon] || 'var(--deep)', title: title || { success: 'تم بنجاح', error: 'تعذر الإجراء', warning: 'تنبيه' }[icon] || 'تنبيه', text, confirmButtonText: button, customClass: { htmlContainer: 'swal-pre' } })
}

/** Text input. Returns the trimmed text, or null when cancelled. `required` blocks empty answers with a message. */
export async function promptBox(title, { text, placeholder = '', value = '', required = true, multiline = true, confirmText = 'حفظ', minLength = 0 } = {}) {
  const r = await (await swal()).fire({
    title,
    text,
    input: multiline ? 'textarea' : 'text',
    inputValue: value,
    inputPlaceholder: placeholder,
    inputAttributes: { dir: 'auto' },
    showCancelButton: true,
    confirmButtonText: confirmText,
    cancelButtonText: 'إلغاء',
    inputValidator: (v) => {
      const s = (v || '').trim()
      if (required && !s) return 'هذا الحقل مطلوب'
      if (minLength && s.length < minLength) return `اكتب ${minLength} أحرف على الأقل`
      return null
    },
  })
  return r.isConfirmed ? (r.value || '').trim() : null
}

/** One choice out of a few. options: { key: label }. Returns the chosen key, or null when cancelled. */
export async function chooseBox(title, options, { text, value, confirmText = 'متابعة' } = {}) {
  const r = await (await swal()).fire({
    title,
    text,
    input: 'radio',
    inputOptions: options,
    inputValue: value ?? Object.keys(options)[0],
    showCancelButton: true,
    confirmButtonText: confirmText,
    cancelButtonText: 'إلغاء',
    customClass: { ...BASE.customClass, input: 'hc-swal-radio' },
    inputValidator: (v) => (v ? null : 'اختر واحدًا'),
  })
  return r.isConfirmed ? r.value : null
}

export default { confirm: confirmBox, alert: alertBox, prompt: promptBox, choose: chooseBox }
