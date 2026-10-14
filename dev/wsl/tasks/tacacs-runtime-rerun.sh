# Idempotency check on an EXISTING TACACS host: must keep DB config, tac_plus.conf and service state.
sec(){ echo; echo "===== $*"; }
sec "Before"; md5sum /etc/tac_plus.conf /etc/tacacs/tacacs-db.conf; systemctl is-active tac_plus; pgrep -x tac_plus | xargs -r ps -o pid=,lstart= -p
sec "Run install/11-tacacs-runtime.sh"; bash "$REPO/install/11-tacacs-runtime.sh" </dev/null; echo "rc=$?"
sec "After"; md5sum /etc/tac_plus.conf /etc/tacacs/tacacs-db.conf; systemctl is-active tac_plus; pgrep -x tac_plus | xargs -r ps -o pid=,lstart= -p
