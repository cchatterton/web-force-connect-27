import { LightningElement, track } from 'lwc';
import status from '@salesforce/apex/WFC27_Admin.status';
import getTrips from '@salesforce/apex/WFC27_Admin.trips';
import setBatchSize from '@salesforce/apex/WFC27_Admin.setBatchSize';
import setTrainPaused from '@salesforce/apex/WFC27_Admin.setTrainPaused';
import { ShowToastEvent } from 'lightning/platformShowToastEvent';

export default class Wfc27Console extends LightningElement {
  @track status;
  @track trips = [];
  error;
  batchSize;
  heartbeatTimer;
  progressTimer;
  heartbeatPercent = 0;
  tripPeriod = 'hour';
  tripDay = new Date().toISOString().slice(0, 10);
  tripHour = String(new Date().getUTCHours());
  tripPeriods = [{ label: 'Last hour', value: 'hour' }, { label: 'Last 24 hours', value: 'day' }, { label: 'Day (UTC)', value: 'date' }, { label: 'Hour in day (UTC)', value: 'date_hour' }];
  tripHours = Array.from({ length: 24 }, (_, hour) => ({ label: `${String(hour).padStart(2, '0')}:00`, value: String(hour) }));
  tripColumns = [{ label: 'Sync', fieldName: 'recordUrl', type: 'url', typeAttributes: { label: { fieldName: 'Name' } } },
    { label: 'Departed', fieldName: 'CreatedDate', type: 'date', typeAttributes: { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' } },
    { label: 'Packets sent', fieldName: 'Sent_Count__c', type: 'number' }, { label: 'Packets received', fieldName: 'Received_Count__c', type: 'number' },
    { label: 'Result', fieldName: 'Result__c' }, { label: 'Error', fieldName: 'Error__c' }];

  connectedCallback() {
    this.refresh();
    this.heartbeatTimer = window.setInterval(() => this.refresh(), 10000);
    this.progressTimer = window.setInterval(() => this.updateHeartbeatProgress(), 250);
  }
  disconnectedCallback() { window.clearInterval(this.heartbeatTimer); window.clearInterval(this.progressTimer); }
  async refresh() {
    try {
      const [state, rows] = await Promise.all([status(), getTrips({ period: this.tripPeriod, day: this.tripDay, hour: Number(this.tripHour) })]);
      this.status = state;
      this.trips = rows.map(row => ({ ...row, recordUrl: `/lightning/r/WFC27_Trip__c/${row.Id}/view` }));
      this.updateHeartbeatProgress();
      this.error = null;
    } catch (error) { this.error = error.body?.message || error.message; }
  }
  updateHeartbeatProgress() {
    const last = this.status?.lastTrain ? new Date(this.status.lastTrain).getTime() : NaN;
    this.heartbeatPercent = !this.status?.trainPaused && Number.isFinite(last) ? Math.min(100, Math.max(0, Math.floor((Date.now() - last) / 600))) : 0;
  }
  get heartbeatStyle() { return `width: ${this.heartbeatPercent}%`; }
  get heartbeatCountdown() {
    if (this.status?.trainPaused) return '—';
    if (!this.status?.lastTrain) return '01:00';
	const elapsed = Math.max(0, Date.now() - new Date(this.status.lastTrain).getTime());
	if (elapsed >= 60000) {
	  const overdue = Math.floor((elapsed - 60000) / 1000);
	  return `+${String(Math.floor(overdue / 60)).padStart(2, '0')}:${String(overdue % 60).padStart(2, '0')}`;
	}
	const seconds = Math.ceil((60000 - elapsed) / 1000);
	return seconds === 60 ? '01:00' : `00:${String(seconds).padStart(2, '0')}`;
  }
	get trainStateLabel() { return this.status?.trainPaused ? 'Paused' : !this.status?.lastTrain ? 'Ready to start' : this.heartbeatPercent >= 100 ? 'Awaiting train' : 'Running'; }
  get trainControlIcon() { return this.status?.trainPaused || !this.status?.lastTrain ? 'utility:play' : 'utility:pause'; }
  get trainControlLabel() { return this.status?.trainPaused || !this.status?.lastTrain ? 'Play sync train' : 'Pause sync train'; }
  changeTripPeriod(event) { this.tripPeriod = event.detail.value; this.refresh(); }
  changeTripDay(event) { this.tripDay = event.detail.value; this.refresh(); }
  changeTripHour(event) { this.tripHour = event.detail.value; this.refresh(); }
  changeBatch(event) { this.batchSize = event.detail.value; }
  async saveBatch() {
    try { await setBatchSize({ size: Number(this.batchSize) }); await this.refresh(); this.dispatchEvent(new ShowToastEvent({ title: 'Train capacity saved', variant: 'success' })); }
    catch (error) { this.error = error.body?.message || error.message; }
  }
  async toggleTrain() {
    try {
      const paused = Boolean(this.status?.lastTrain) && !this.status?.trainPaused;
      await setTrainPaused({ paused });
      await this.refresh();
      this.dispatchEvent(new ShowToastEvent({ title: paused ? 'Train paused' : 'Train running', variant: 'success' }));
    } catch (error) { this.error = error.body?.message || error.message; }
  }
}
