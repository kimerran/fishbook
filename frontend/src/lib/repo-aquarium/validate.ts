export const REPO_PATH_RE = /^[A-Za-z0-9._-]{1,100}$/;

export function isValidPathSegment(s: string): boolean {
  return REPO_PATH_RE.test(s);
}
