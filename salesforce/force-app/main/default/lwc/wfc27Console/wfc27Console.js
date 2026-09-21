import { LightningElement, track } from 'lwc';
import objects from '@salesforce/apex/WFC27_Admin.objects';
import fields from '@salesforce/apex/WFC27_Admin.fields';
import status from '@salesforce/apex/WFC27_Admin.status';
import getTrips from '@salesforce/apex/WFC27_Admin.trips';
import saveObject from '@salesforce/apex/WFC27_Admin.saveObject';
import saveField from '@salesforce/apex/WFC27_Admin.saveField';
import setBatchSize from '@salesforce/apex/WFC27_Admin.setBatchSize';
import setTrainPaused from '@salesforce/apex/WFC27_Admin.setTrainPaused';
import journey from '@salesforce/apex/WFC27_Admin.journey';
import startBaseSync from '@salesforce/apex/WFC27_Admin.startBaseSync';
import fieldRules from '@salesforce/apex/WFC27_Admin.fieldRules';
import { ShowToastEvent } from 'lightning/platformShowToastEvent';

export default class Wfc27Console extends LightningElement {
  @track rows = [];
  @track fieldRows = [];
  @track fieldRules = [];
  @track status;
  error;
  selectedObject;
  bindingId;
  postType = '';
  eligibleStatus = 'draft';
  active = false;
  draftDays;
  binDays;
  batchSize;
  sourceField;
  targetType = 'post';
  wpKey = '';
  searchId = '';
  heartbeatTimer;
  progressTimer;
  heartbeatPercent = 0;
  transportVisible = true;
  activeTab = 'transport';
  objectOptionsList = [];
  objectSearch = '';
  objectSuggestionsOpen = false;
  @track trips = [];
  tripPeriod = 'hour';
  tripDay = new Date().toISOString().slice(0, 10);
  tripHour = new Date().getUTCHours();
  tripPeriods = [{ label: 'Last hour', value: 'hour' }, { label: 'Last 24 hours', value: 'day' }, { label: 'Day (UTC)', value: 'date' }, { label: 'Hour in day (UTC)', value: 'date_hour' }];
  tripHours = Array.from({ length: 24 }, (_, hour) => ({ label: `${String(hour).padStart(2, '0')}:00`, value: String(hour) }));
  tripColumns = [{ label: 'Departed', fieldName: 'CreatedDate', type: 'date', typeAttributes: { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' } },
    { label: 'Packet', fieldName: 'packetLabel' }, { label: 'Posts', fieldName: 'Post_Count__c', type: 'number' },
    { label: 'Meta', fieldName: 'Meta_Count__c', type: 'number' }, { label: 'Result', fieldName: 'Result__c' }, { label: 'Error', fieldName: 'Error__c' }];
  @track journeyData;
  packetColumns = [
    { label: 'Packet', fieldName: 'Id' },
    { label: 'Status', fieldName: 'Status__c' },
    { label: 'Posts', fieldName: 'Post_Count__c', type: 'number' },
    { label: 'Meta', fieldName: 'Meta_Count__c', type: 'number' },
    { label: 'Error', fieldName: 'Error__c' }
  ];
  objectColumns = [
    { label: 'Salesforce object', fieldName: 'displayName' },
    { label: 'WordPress post type', fieldName: 'postType' },
    { label: 'Eligible status', fieldName: 'eligibleStatus' },
    { label: 'Eligibility field', fieldName: 'eligibilityLabel' },
    { label: 'Active', fieldName: 'active', type: 'boolean' },
    { label: 'Sync', fieldName: 'syncLabel' },
    { type: 'button', typeAttributes: { label: 'Edit', name: 'edit', variant: 'base' } }
  ];
  journeyColumns = [{ label: 'Queue ID', fieldName: 'Id' }, { label: 'Kind', fieldName: 'Kind__c' },
    { label: 'Status', fieldName: 'Status__c' }, { label: 'Packet', fieldName: 'Packet__c' }];
  fieldColumns = [{ label: 'Salesforce field', fieldName: 'Source_Field__c' }, { label: 'Target', fieldName: 'Target_Type__c' },
    { label: 'WordPress key', fieldName: 'WP_Key__c' }, { label: 'Active', fieldName: 'Active__c', type: 'boolean' }];
  targetOptions = [{ label: 'Post field', value: 'post' }, { label: 'Post meta', value: 'meta' }];
  statusOptions = [{ label: 'Draft', value: 'draft' }, { label: 'Publish', value: 'publish' }, { label: 'Pending review', value: 'pending' }, { label: 'Private', value: 'private' }];

  connectedCallback() {
    this.refresh();
    this.heartbeatTimer = window.setInterval(() => {
      if (this.activeTab === 'transport') this.refreshTransport();
      if (this.activeTab === 'objects') this.refreshSelectedFields();
    }, 10000);
    this.progressTimer = window.setInterval(() => { if (this.transportVisible) this.updateHeartbeatProgress(); }, 250);
  }
  disconnectedCallback() { window.clearInterval(this.heartbeatTimer); window.clearInterval(this.progressTimer); }
  async refresh() {
    try { [this.rows, this.status] = await Promise.all([objects(), status()]); this.updateObjectOptions(); this.updateHeartbeatProgress(); await this.refreshTrips(); this.error = null; }
    catch (error) { this.error = error.body?.message || error.message; }
  }
  async refreshTransport() {
    if (!this.transportVisible) return;
    try { this.status = await status(); this.updateHeartbeatProgress(); await this.refreshTrips(); this.error = null; }
    catch (error) { this.error = error.body?.message || error.message; }
  }
  showTransport() { this.activeTab = 'transport'; this.transportVisible = true; this.refreshTransport(); }
  showObjects() { this.activeTab = 'objects'; this.transportVisible = false; this.refreshSelectedFields(); }
  showFields() { this.activeTab = 'fields'; this.transportVisible = false; }
  async refreshTrips() {
    const rows = await getTrips({ period: this.tripPeriod, day: this.tripDay, hour: Number(this.tripHour) });
    this.trips = rows.map(row => ({ ...row, packetLabel: row.Packet_ID__c || 'Empty packet' }));
  }
  changeTripPeriod(event) { this.tripPeriod = event.detail.value; this.refreshTrips(); }
  changeTripDay(event) { this.tripDay = event.detail.value; this.refreshTrips(); }
  changeTripHour(event) { this.tripHour = event.detail.value; this.refreshTrips(); }
  updateObjectOptions() {
    const query = this.objectSearch.trim().toLowerCase();
    const matches = this.rows.filter(row => (!row.bindingId || row.api === this.selectedObject) &&
      (!query || row.label.toLowerCase().includes(query) || row.api.toLowerCase().includes(query)));
    if (query) matches.sort((a, b) => Number(b.label.toLowerCase().startsWith(query)) - Number(a.label.toLowerCase().startsWith(query)) || a.label.localeCompare(b.label));
    this.objectOptionsList = (query ? matches.slice(0, 50) : matches).map(row => ({ label: `${row.label} (${row.api})`, value: row.api }));
  }
  updateHeartbeatProgress() {
    const last = this.status?.lastTrain ? new Date(this.status.lastTrain).getTime() : NaN;
    this.heartbeatPercent = !this.status?.trainPaused && Number.isFinite(last) ? Math.min(100, Math.max(0, Math.floor((Date.now() - last) / 600))) : 0;
  }
  get heartbeatStyle() { return `width: ${this.heartbeatPercent}%`; }
  get heartbeatCountdown() {
    if (this.status?.trainPaused) return '—';
    if (!this.status?.lastTrain) return '01:00';
    const seconds = Math.max(0, Math.ceil((60000 - (Date.now() - new Date(this.status.lastTrain).getTime())) / 1000));
    return `00:${String(seconds).padStart(2, '0')}`.replace('00:60', '01:00');
  }
  get trainStateLabel() { return this.status?.trainPaused ? 'Paused' : !this.status?.lastTrain ? 'Ready to start' : this.heartbeatPercent >= 100 ? 'Train due' : 'Running'; }
  get trainControlIcon() { return this.status?.trainPaused || !this.status?.lastTrain ? 'utility:play' : 'utility:pause'; }
  get trainControlLabel() { return this.status?.trainPaused || !this.status?.lastTrain ? 'Play sync train' : 'Pause sync train'; }
  get objectSuggestions() { return this.objectSuggestionsOpen ? this.objectOptionsList.slice(0, 20) : []; }
  get configuredBindings() { return this.rows.filter(row => row.bindingId).map(row => ({ ...row,
    displayName: `${row.label} (${row.api})`,
    eligibilityLabel: row.hasFlag ? 'Present' : 'Missing',
    syncLabel: !row.active ? 'Paused' : row.hasFlag ? 'Ready' : 'Waiting for eligibility field'
  })).sort((a, b) => a.displayName.localeCompare(b.displayName)); }
  get objectFormTitle() { return this.bindingId ? 'Edit object binding' : 'Add object binding'; }
  get selectedRow() { return this.rows.find(row => row.api === this.selectedObject); }
  get nearEligibilityField() { return this.selectedRow?.hasFlag ? null : this.fieldRows.find(field => field.api.startsWith('WFC27_Eligible') && field.api !== 'WFC27_Eligible__c' && field.type?.toLowerCase() === 'boolean')?.api; }
  get fieldOptions() { return this.fieldRows.map(row => ({ label: `${row.label} (${row.api})`, value: row.api })); }
  get saveDisabled() { return !this.selectedRow || !this.postType; }
  get baseDisabled() { return !this.bindingId || !this.active || !this.selectedRow?.hasFlag; }
  get fieldSaveDisabled() { return !this.bindingId || !this.sourceField || !this.wpKey; }
  async chooseObject(event) {
    this.selectedObject = event.detail.value;
    this.objectSuggestionsOpen = false;
    const selected = this.rows.find(row => row.api === this.selectedObject);
    this.objectSearch = selected ? `${selected.label} (${selected.api})` : '';
    this.updateObjectOptions();
    const row = this.selectedRow;
    this.bindingId = row.bindingId;
    this.postType = row.postType || '';
    this.eligibleStatus = row.eligibleStatus || 'draft';
    this.active = row.active;
    this.draftDays = row.draftDays;
    this.binDays = row.binDays;
    this.sourceField = null;
    this.fieldRows = [];
    try {
      await this.refreshSelectedFields();
      this.fieldRules = this.bindingId ? await fieldRules({ bindingId: this.bindingId }) : [];
      this.error = null;
    }
    catch (error) { this.error = error.body?.message || error.message; }
  }
  async refreshSelectedFields() {
    const objectApi = this.selectedObject;
    if (!objectApi) return;
    try {
      const currentFields = await fields({ objectApi });
      if (objectApi !== this.selectedObject) return;
      this.fieldRows = currentFields;
      const hasFlag = currentFields.some(field => field.api === 'WFC27_Eligible__c' && field.type?.toLowerCase() === 'boolean');
      this.rows = this.rows.map(row => row.api === objectApi ? { ...row, hasFlag } : row);
      this.error = null;
    } catch (error) { this.error = error.body?.message || error.message; }
  }
  async editObjectRule(event) { await this.chooseObject({ detail: { value: event.currentTarget.dataset.api } }); }
  changePostType(event) { this.postType = event.detail.value; }
  changeObjectSearch(event) { this.objectSearch = event.target.value || ''; this.objectSuggestionsOpen = true; this.updateObjectOptions(); }
  focusObjectSearch() { this.objectSuggestionsOpen = true; this.updateObjectOptions(); }
  selectObjectSuggestion(event) { this.chooseObject({ detail: { value: event.currentTarget.dataset.api } }); }
  changeEligibleStatus(event) { this.eligibleStatus = event.detail.value; }
  changeActive(event) { this.active = event.detail.checked; }
  changeDraft(event) { this.draftDays = event.detail.value; }
  changeBin(event) { this.binDays = event.detail.value; }
  changeBatch(event) { this.batchSize = event.detail.value; }
  changeSearch(event) { this.searchId = event.detail.value; }
  chooseField(event) { this.sourceField = event.detail.value; }
  chooseTarget(event) { this.targetType = event.detail.value; }
  changeKey(event) { this.wpKey = event.detail.value; }
  async saveObjectRule() {
    try {
      this.bindingId = await saveObject({ objectApi: this.selectedObject, postType: this.postType, eligibleStatus: this.eligibleStatus, active: this.active,
        draftDays: this.draftDays === '' || this.draftDays == null ? null : Number(this.draftDays),
        binDays: this.binDays === '' || this.binDays == null ? null : Number(this.binDays) });
      this.dispatchEvent(new ShowToastEvent({ title: 'Object binding saved', variant: 'success' }));
      await this.refresh();
    } catch (error) { this.error = error.body?.message || error.message; }
  }
  async saveFieldRule() {
    try {
      await saveField({ bindingId: this.bindingId, sourceField: this.sourceField, targetType: this.targetType, wpKey: this.wpKey });
      this.dispatchEvent(new ShowToastEvent({ title: 'Field binding added', variant: 'success' }));
      this.wpKey = '';
      this.fieldRules = await fieldRules({ bindingId: this.bindingId });
    } catch (error) { this.error = error.body?.message || error.message; }
  }
  async saveBatch() {
    try {
      await setBatchSize({ size: Number(this.batchSize) });
      this.dispatchEvent(new ShowToastEvent({ title: 'Batch size saved', variant: 'success' }));
      await this.refresh();
    } catch (error) { this.error = error.body?.message || error.message; }
  }
  async toggleTrain() {
    try {
      const paused = Boolean(this.status?.lastTrain) && !this.status?.trainPaused;
      await setTrainPaused({ paused });
      this.dispatchEvent(new ShowToastEvent({ title: paused ? 'Sync train paused' : 'Sync train running', variant: 'success' }));
      await this.refreshTransport();
    }
    catch (error) { this.error = error.body?.message || error.message; }
  }
  async queueBaseSync() {
    try { await startBaseSync({ bindingId: this.bindingId }); this.dispatchEvent(new ShowToastEvent({ title: 'Base sync queued', variant: 'success' })); }
    catch (error) { this.error = error.body?.message || error.message; }
  }
  async showJourney() {
    try { this.journeyData = await journey({ sourceId: this.searchId }); this.error = null; }
    catch (error) { this.error = error.body?.message || error.message; }
  }
}
