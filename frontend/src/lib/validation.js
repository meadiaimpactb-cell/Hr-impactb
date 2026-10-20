import * as z from 'zod'
import { isValidPhoneNumber } from 'libphonenumber-js'

/**
 * Shared Arabic validation rules (zod), used with vee-validate through toTypedSchema.
 * The same rules are enforced again by the server; the client just answers instantly.
 */

/** Arabic messages for every zod issue, so no English "Required" / "Invalid" ever reaches a form. */
const arabicErrorMap = (issue, ctx) => {
  switch (issue.code) {
    case z.ZodIssueCode.invalid_type:
      if (['undefined', 'null', 'nan'].includes(issue.received)) return { message: 'هذا الحقل مطلوب' }
      return { message: 'قيمة غير صحيحة' }
    case z.ZodIssueCode.too_small:
      if (issue.type === 'string') return { message: issue.minimum === 1 ? 'هذا الحقل مطلوب' : `يجب ألا يقل عن ${issue.minimum} أحرف` }
      if (issue.type === 'number') return { message: `يجب ألا تقل القيمة عن ${issue.minimum}` }
      return { message: 'القيمة صغيرة جدًا' }
    case z.ZodIssueCode.too_big:
      if (issue.type === 'string') return { message: `يجب ألا يزيد عن ${issue.maximum} حرف` }
      if (issue.type === 'number') return { message: `يجب ألا تزيد القيمة عن ${issue.maximum}` }
      return { message: 'القيمة كبيرة جدًا' }
    case z.ZodIssueCode.invalid_string:
      if (issue.validation === 'email') return { message: 'صيغة البريد الإلكتروني غير صحيحة' }
      if (issue.validation === 'url') return { message: 'الرابط غير صحيح' }
      return { message: 'صيغة غير صحيحة' }
    case z.ZodIssueCode.invalid_enum_value:
      return { message: 'اختيار غير صالح' }
    default:
      return { message: ctx.defaultError }
  }
}

export function installZodArabic() { z.setErrorMap(arabicErrorMap) }
installZodArabic()

const str = (msg) => z.string({ required_error: msg, invalid_type_error: msg })

export const requiredString = (label) => str(`${label} مطلوب`).trim().min(1, `${label} مطلوب`)
export const optionalString = z.string().trim().optional().nullable().or(z.literal(''))

export const nameField = str('الاسم مطلوب').trim().min(1, 'الاسم مطلوب').min(3, 'الاسم قصير جدًا (3 أحرف على الأقل)').max(150, 'الاسم طويل جدًا')
export const loginField = str('اسم المستخدم مطلوب').trim().min(1, 'اسم المستخدم أو البريد أو رقم الموظف مطلوب')
export const emailField = str('البريد الإلكتروني مطلوب').trim().min(1, 'البريد الإلكتروني مطلوب').email('صيغة البريد الإلكتروني غير صحيحة')
export const optionalEmail = z.string().trim().email('صيغة البريد الإلكتروني غير صحيحة').optional().nullable().or(z.literal(''))

/** Password policy of the platform: 8+ characters with a letter, a number and a symbol (the server enforces the same). */
export const PASSWORD_MIN = 8
export const passwordField = str('كلمة المرور مطلوبة')
  .min(1, 'كلمة المرور مطلوبة')
  .min(PASSWORD_MIN, `كلمة المرور يجب ألا تقل عن ${PASSWORD_MIN} أحرف`)
  .regex(/[A-Za-z]/, 'يجب أن تحتوي على حرف إنجليزي واحد على الأقل')
  .regex(/[0-9]/, 'يجب أن تحتوي على رقم واحد على الأقل')
  .regex(/[^A-Za-z0-9]/, 'يجب أن تحتوي على رمز واحد على الأقل مثل @ أو #')
export const passwordConfirmField = str('تأكيد كلمة المرور مطلوب').min(1, 'تأكيد كلمة المرور مطلوب')
/** Wraps an object schema so `password_confirmation` must equal `password`. */
export const withConfirmation = (shape) => z.object(shape).refine((v) => v.password === v.password_confirmation, { message: 'كلمتا المرور غير متطابقتين', path: ['password_confirmation'] })

/** Saudi national ID / iqama: 10 digits starting with 1 (citizen) or 2 (resident). */
export const saudiIdField = z.string().trim().regex(/^\d{10}$/, 'رقم الهوية/الإقامة 10 أرقام')
/** Any national ID (digits only) — used for non-Saudi records. */
export const nationalIdField = z.string().trim().regex(/^\d{5,20}$/, 'رقم الهوية أرقام فقط')
export const commercialRegField = z.string().trim().regex(/^\d{10}$/, 'السجل التجاري يجب أن يكون 10 أرقام')
export const phoneField = z.string().trim().refine((v) => !v || isValidPhoneNumber(v), 'رقم الجوال غير صحيح')

/** Saudi IBAN: SA + 22 digits with a valid ISO 7064 mod-97 check. */
export function ibanValid(v) {
  const s = (v || '').replace(/\s/g, '').toUpperCase()
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test(s)) return false
  if (s.startsWith('SA') && !/^SA\d{22}$/.test(s)) return false
  let r = 0
  for (const ch of (s.slice(4) + s.slice(0, 4)).replace(/[A-Z]/g, (c) => c.charCodeAt(0) - 55)) r = (r * 10 + +ch) % 97
  return r === 1
}
export const ibanField = z.string().trim().refine((v) => !v || ibanValid(v), 'رقم الآيبان غير صحيح')

/** Live password checklist for the UI. */
export function passwordChecks(v = '') {
  return { length: v.length >= PASSWORD_MIN, letter: /[A-Za-z]/.test(v), number: /[0-9]/.test(v), symbol: /[^A-Za-z0-9]/.test(v) }
}

/** Run a zod schema outside vee-validate (e.g. big forms): returns { field: [message] } or {}. */
export function check(schema, values) {
  const r = schema.safeParse(values)
  if (r.success) return {}
  const out = {}
  for (const i of r.error.issues) { const k = i.path.join('.') || '_'; (out[k] ||= []).push(i.message) }
  return out
}

export { z }
