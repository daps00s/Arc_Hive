// admin_dashboard.js - Admin Dashboard JavaScript for ArcHive

// Constants for configuration
const ITEMS_PER_PAGE_OPTIONS = [5, 10, 20, 'all'];
const CHART_CONFIG = {
  FileUploadTrends: {
    type: 'line',
    label: 'File Uploads',
    title: 'File Upload Trends (Last 7 Days)',
    xAxis: 'Date',
    yAxis: 'Number of Uploads',
    borderColor: '#3498db',
    backgroundColor: 'rgba(52, 152, 219, 0.2)',
    processData: (data) => {
      const uploadDates = [...new Set(data.map(item => new Date(item.upload_date).toLocaleDateString()))];
      return {
        labels: uploadDates,
        data: uploadDates.map(date =>
          data.filter(item => new Date(item.upload_date).toLocaleDateString() === date).length
        ),
      };
    },
    dataKey: 'fileUploadTrends',
  },
  FileDistribution: {
    type: 'bar',
    label: 'Files by Document Type',
    title: 'File Distribution by Document Type',
    xAxis: 'Document Type',
    yAxis: 'Number of Files',
    borderColor: '#27ae60',
    backgroundColor: '#2ecc71',
    processData: (data) => {
      const docTypes = [...new Set(data.map(item => item.document_type))];
      return {
        labels: docTypes,
        data: docTypes.map(type => data.filter(item => item.document_type === type).length),
      };
    },
    dataKey: 'fileDistribution',
  },
  UsersPerDepartment: {
    type: 'bar',
    label: 'Users per Department',
    title: 'Users Per Department',
    xAxis: 'Department',
    yAxis: 'Number of Users',
    borderColor: '#c0392b',
    backgroundColor: '#e74c3c',
    processData: (data) => ({
      labels: data.map(item => item.department_name),
      data: data.map(item => item.user_count),
    }),
    dataKey: 'usersPerDepartment',
  },
  DocumentCopies: {
    type: 'bar',
    label: 'Copy Count per File',
    title: 'Document Copies Details',
    xAxis: 'File Name',
    yAxis: 'Number of Copies',
    borderColor: '#f39c12',
    backgroundColor: '#f1c40f',
    processData: (data) => ({
      labels: data.map(item => item.file_name),
      data: data.map(item => item.copy_count),
    }),
    dataKey: 'documentCopies',
  },
  PendingRequests: {
    title: 'Pending Requests',
    processData: (data) => ({ labels: [], data: [] }),
    dataKey: 'pendingRequestsDetails',
  },
  RetrievalHistory: {
    title: 'Retrieval History',
    processData: (data) => ({ labels: [], data: [] }),
    dataKey: 'retrievalHistory',
  },
  AccessHistory: {
    title: 'Access History',
    processData: (data) => ({ labels: [], data: [] }),
    dataKey: 'accessHistory',
  },
};

// Utility Functions
const sanitizeHTML = (str) => {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
};

const escapeCsvField = (str) => {
  if (str == null) return '""';
  const stringified = String(str);
  if (stringified.includes('"') || stringified.includes(',') || stringified.includes('\n')) {
    return `"${stringified.replace(/"/g, '""')}"`;
  }
  return `"${stringified}"`;
};

// Chart Initialization
const initializeChart = (canvasId, config, data) => {
  const canvas = document.getElementById(canvasId);
  if (!canvas) {
    console.warn(`Canvas element with ID ${canvasId} not found`);
    return null;
  }

  if (!data || data.length === 0) {
    canvas.parentElement.insertAdjacentHTML(
      'beforeend',
      '<p class="no-data">No data available for this chart.</p>'
    );
    return null;
  }

  const { labels, data: chartData } = config.processData(data);
  const chart = new Chart(canvas, {
    type: config.type,
    data: {
      labels,
      datasets: [{
        label: config.label,
        data: chartData,
        borderColor: config.borderColor,
        backgroundColor: config.backgroundColor,
        borderWidth: 1,
        fill: config.type === 'line',
        tension: config.type === 'line' ? 0.4 : undefined,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      aspectRatio: 1.5,
      plugins: {
        legend: { position: 'bottom', labels: { padding: 20 } },
        title: { display: true, text: config.title, font: { size: 16 } },
      },
      scales: {
        x: {
          title: { display: true, text: config.xAxis, font: { size: 14 } },
          ticks: { autoSkip: true, maxTicksLimit: 10 }
        },
        y: {
          title: { display: true, text: config.yAxis, font: { size: 14 } },
          beginAtZero: true
        },
      },
    },
  });
  return chart;
};

// Table Generation
const generateTableContent = (chartType, page = 1, itemsPerPage = 5) => {
  const data = dashboardData[CHART_CONFIG[chartType].dataKey] || [];
  if (!data.length) return '<p class="no-data">No data available.</p>';

  const start = (page - 1) * itemsPerPage;
  const end = itemsPerPage === 'all' ? data.length : start + itemsPerPage;
  const slicedData = data.slice(start, end);

  const tableHeaders = {
    FileUploadTrends: [
      'File Name', 'Document Type', 'Uploader', "Uploader's Department",
      'Intended Destination', 'Upload Date/Time'
    ],
    FileDistribution: [
      'File Name', 'Document Type', 'Sender', 'Recipient',
      'Time Sent', 'Time Received', 'Department/Subdepartment'
    ],
    UsersPerDepartment: ['Department', 'User Count'],
    DocumentCopies: ['File Name', 'Copy Count', 'Offices with Copy', 'Physical Duplicates'],
    PendingRequests: ['File Name', 'Requester', "Requester's Department", 'Physical Storage'],
    RetrievalHistory: [
      'Transaction ID', 'Type', 'Status', 'Time', 'User',
      'File Name', 'Department', 'Physical Storage'
    ],
    AccessHistory: ['Transaction ID', 'Time', 'User', 'File Name', 'Type', 'Department'],
  };

  const headers = tableHeaders[chartType] || [];
  const tableRows = slicedData.map(entry => {
    switch (chartType) {
      case 'FileUploadTrends':
        return `
          <tr>
            <td>${sanitizeHTML(entry.document_name)}</td>
            <td>${sanitizeHTML(entry.document_type)}</td>
            <td>${sanitizeHTML(entry.uploader_name)}</td>
            <td>${sanitizeHTML(entry.uploader_department || 'None')}${entry.uploader_subdepartment ? ' / ' + sanitizeHTML(entry.uploader_subdepartment) : ''}</td>
            <td>${sanitizeHTML(entry.target_department_name || 'None')}</td>
            <td>${new Date(entry.upload_date).toLocaleString()}</td>
          </tr>`;
      case 'FileDistribution':
        return `
          <tr>
            <td>${sanitizeHTML(entry.document_name)}</td>
            <td>${sanitizeHTML(entry.document_type)}</td>
            <td>${sanitizeHTML(entry.sender_name)}</td>
            <td>${sanitizeHTML(entry.receiver_name)}</td>
            <td>${entry.time_sent ? new Date(entry.time_sent).toLocaleString() : 'N/A'}</td>
            <td>${entry.time_received ? new Date(entry.time_received).toLocaleString() : 'N/A'}</td>
            <td>${sanitizeHTML(entry.department_name || 'None')}${entry.sub_department_name ? ' / ' + sanitizeHTML(entry.sub_department_name) : ''}</td>
          </tr>`;
      case 'UsersPerDepartment':
        return `
          <tr>
            <td>${sanitizeHTML(entry.department_name)}</td>
            <td>${sanitizeHTML(entry.user_count.toString())}</td>
          </tr>`;
      case 'DocumentCopies':
        return `
          <tr>
            <td>${sanitizeHTML(entry.file_name)}</td>
            <td>${sanitizeHTML(entry.copy_count.toString())}</td>
            <td>${sanitizeHTML(entry.offices_with_copy || 'None')}</td>
            <td>${sanitizeHTML(entry.physical_duplicates || 'None')}</td>
          </tr>`;
      case 'PendingRequests':
        return `
          <tr>
            <td>${sanitizeHTML(entry.file_name)}</td>
            <td>${sanitizeHTML(entry.requester_name)}</td>
            <td>${sanitizeHTML(entry.requester_department || 'None')}${entry.requester_subdepartment ? ' / ' + sanitizeHTML(entry.requester_subdepartment) : ''}</td>
            <td>${sanitizeHTML(entry.storage_location || 'None')}</td>
          </tr>`;
      case 'RetrievalHistory':
        return `
          <tr>
            <td>${sanitizeHTML(entry.transaction_id.toString())}</td>
            <td>${sanitizeHTML(entry.type)}</td>
            <td>${sanitizeHTML(entry.status)}</td>
            <td>${new Date(entry.time).toLocaleString()}</td>
            <td>${sanitizeHTML(entry.user_name)}</td>
            <td>${sanitizeHTML(entry.file_name)}</td>
            <td>${sanitizeHTML(entry.department_name || 'None')}</td>
            <td>${sanitizeHTML(entry.storage_location || 'None')}</td>
          </tr>`;
      case 'AccessHistory':
        return `
          <tr>
            <td>${sanitizeHTML(entry.transaction_id.toString())}</td>
            <td>${new Date(entry.time).toLocaleString()}</td>
            <td>${sanitizeHTML(entry.user_name)}</td>
            <td>${sanitizeHTML(entry.file_name)}</td>
            <td>${sanitizeHTML(entry.type)}</td>
            <td>${sanitizeHTML(entry.department_name || 'None')}</td>
          </tr>`;
      default:
        return '';
    }
  }).join('');

  return `
    <table class="data-table">
      <thead>
        <tr>
          ${headers.map(header => `<th>${sanitizeHTML(header)}</th>`).join('')}
        </tr>
      </thead>
      <tbody>
        ${tableRows}
      </tbody>
    </table>
  `;
};

// Generate Report Content
const generateReportContent = (chartType) => {
  const data = dashboardData[CHART_CONFIG[chartType].dataKey] || [];
  let chartImage = '';
  if (CHART_CONFIG[chartType].type) {
    const canvas = document.getElementById(`${chartType}Chart`);
    if (canvas) {
      chartImage = canvas.toDataURL('image/png', 1.0);
    }
  }

  const tableHeaders = {
    FileUploadTrends: [
      'File Name', 'Document Type', 'Uploader', "Uploader's Department",
      'Intended Destination', 'Upload Date/Time'
    ],
    FileDistribution: [
      'File Name', 'Document Type', 'Sender', 'Recipient',
      'Time Sent', 'Time Received', 'Department/Subdepartment'
    ],
    UsersPerDepartment: ['Department', 'User Count'],
    DocumentCopies: ['File Name', 'Copy Count', 'Offices with Copy', 'Physical Duplicates'],
    PendingRequests: ['File Name', 'Requester', "Requester's Department", 'Physical Storage'],
    RetrievalHistory: [
      'Transaction ID', 'Type', 'Status', 'Time', 'User',
      'File Name', 'Department', 'Physical Storage'
    ],
    AccessHistory: ['Transaction ID', 'Time', 'User', 'File Name', 'Type', 'Department'],
  };

  const tableRows = data.map(entry => {
    switch (chartType) {
      case 'FileUploadTrends':
        return `
          <tr>
            <td>${sanitizeHTML(entry.document_name)}</td>
            <td>${sanitizeHTML(entry.document_type)}</td>
            <td>${sanitizeHTML(entry.uploader_name)}</td>
            <td>${sanitizeHTML(entry.uploader_department || 'None')}${entry.uploader_subdepartment ? ' / ' + sanitizeHTML(entry.uploader_subdepartment) : ''}</td>
            <td>${sanitizeHTML(entry.target_department_name || 'None')}</td>
            <td>${new Date(entry.upload_date).toLocaleString()}</td>
          </tr>`;
      case 'FileDistribution':
        return `
          <tr>
            <td>${sanitizeHTML(entry.document_name)}</td>
            <td>${sanitizeHTML(entry.document_type)}</td>
            <td>${sanitizeHTML(entry.sender_name)}</td>
            <td>${sanitizeHTML(entry.receiver_name)}</td>
            <td>${entry.time_sent ? new Date(entry.time_sent).toLocaleString() : 'N/A'}</td>
            <td>${entry.time_received ? new Date(entry.time_received).toLocaleString() : 'N/A'}</td>
            <td>${sanitizeHTML(entry.department_name || 'None')}${entry.sub_department_name ? ' / ' + sanitizeHTML(entry.sub_department_name) : ''}</td>
          </tr>`;
      case 'UsersPerDepartment':
        return `
          <tr>
            <td>${sanitizeHTML(entry.department_name)}</td>
            <td>${sanitizeHTML(entry.user_count.toString())}</td>
          </tr>`;
      case 'DocumentCopies':
        return `
          <tr>
            <td>${sanitizeHTML(entry.file_name)}</td>
            <td>${sanitizeHTML(entry.copy_count.toString())}</td>
            <td>${sanitizeHTML(entry.offices_with_copy || 'None')}</td>
            <td>${sanitizeHTML(entry.physical_duplicates || 'None')}</td>
          </tr>`;
      case 'PendingRequests':
        return `
          <tr>
            <td>${sanitizeHTML(entry.file_name)}</td>
            <td>${sanitizeHTML(entry.requester_name)}</td>
            <td>${sanitizeHTML(entry.requester_department || 'None')}${entry.requester_subdepartment ? ' / ' + sanitizeHTML(entry.requester_subdepartment) : ''}</td>
            <td>${sanitizeHTML(entry.storage_location || 'None')}</td>
          </tr>`;
      case 'RetrievalHistory':
        return `
          <tr>
            <td>${sanitizeHTML(entry.transaction_id.toString())}</td>
            <td>${sanitizeHTML(entry.type)}</td>
            <td>${sanitizeHTML(entry.status)}</td>
            <td>${new Date(entry.time).toLocaleString()}</td>
            <td>${sanitizeHTML(entry.user_name)}</td>
            <td>${sanitizeHTML(entry.file_name)}</td>
            <td>${sanitizeHTML(entry.department_name || 'None')}</td>
            <td>${sanitizeHTML(entry.storage_location || 'None')}</td>
          </tr>`;
      case 'AccessHistory':
        return `
          <tr>
            <td>${sanitizeHTML(entry.transaction_id.toString())}</td>
            <td>${new Date(entry.time).toLocaleString()}</td>
            <td>${sanitizeHTML(entry.user_name)}</td>
            <td>${sanitizeHTML(entry.file_name)}</td>
            <td>${sanitizeHTML(entry.type)}</td>
            <td>${sanitizeHTML(entry.department_name || 'None')}</td>
          </tr>`;
      default:
        return '';
    }
  }).join('');

  return `
    <html>
      <head>
        <title>${chartType} Report - ArcHive</title>
        <style>
          body { 
            font-family: var(--font-family, 'Inter', Arial, sans-serif); 
            margin: 1in; 
            color: var(--text-color, #2d3748); 
            line-height: 1.5;
          }
          h1 { 
            font-size: 28px; 
            text-align: center; 
            margin-bottom: 20px; 
            color: var(--secondary-color, #2c3e50); 
          }
          h2 { 
            font-size: 20px; 
            margin: 30px 0 15px; 
            color: var(--secondary-color, #2c3e50); 
          }
          img { 
            max-width: 100%; 
            width: 600px; 
            height: auto;
            display: block; 
            margin: 20px auto; 
            border: 1px solid var(--border-color, #e2e8f0);
          }
          table { 
            width: 100%; 
            max-width: 1000px; 
            border-collapse: collapse; 
            margin: 20px auto; 
            font-size: 10pt; 
            background-color: var(--card-background, #ffffff); 
            box-shadow: var(--shadow, 0 4px 12px rgba(0,0,0,0.08)); 
          }
          th, td { 
            border: 1px solid var(--border-color, #e2e8f0); 
            padding: 10px; 
            text-align: left; 
            word-wrap: break-word; 
          }
          th { 
            background-color: var(--primary-color, #50c878); 
            color: #ffffff; 
            font-weight: 600; 
            text-transform: uppercase; 
          }
          td { 
            color: var(--text-color, #2d3748); 
          }
          tr:nth-child(even) { 
            background-color: #f9fafb; 
          }
          tr:hover { 
            background-color: #f1f5f9; 
          }
          @media print {
            body { margin: 0.5in; }
            table { font-size: 9pt; }
            th { 
              background-color: var(--primary-color, #50c878) !important; 
              -webkit-print-color-adjust: exact; 
              print-color-adjust: exact;
            }
            tr:nth-child(even) { 
              background-color: #f9fafb !important; 
              -webkit-print-color-adjust: exact; 
              print-color-adjust: exact;
            }
            img {
              max-width: 100% !important;
              width: 600px !important;
              height: auto !important;
            }
          }
        </style>
      </head>
      <body>
        <h1>${chartType} Report</h1>
        ${chartImage ? `<img src="${chartImage}" alt="${chartType} Chart">` : '<p>No chart available for this report.</p>'}
        <h2>Data Table</h2>
        <table>
          <thead>
            <tr>
              ${tableHeaders[chartType].map(header => `<th>${sanitizeHTML(header)}</th>`).join('')}
            </tr>
          </thead>
          <tbody>${tableRows}</tbody>
        </table>
      </body>
    </html>
  `;
};

// Generate Printable Report
const generateReport = (chartType) => {
  const reportContent = generateReportContent(chartType);
  const printWindow = window.open('', '_blank');
  printWindow.document.write(reportContent);
  printWindow.document.close();
  printWindow.onload = () => {
    printWindow.focus();
    setTimeout(() => {
      printWindow.print();
    }, 500); // Delay to ensure rendering
  };
};

// Download Report as CSV or PDF
const downloadReport = (chartType, format) => {
  const data = dashboardData[CHART_CONFIG[chartType].dataKey] || [];
  if (!data.length) {
    alert('No data available for download.');
    closeDownloadModal();
    return;
  }

  if (format === 'csv') {
    const headers = {
      FileUploadTrends: ['File Name', 'Document Type', 'Uploader', "Uploader's Department", 'Intended Destination', 'Upload Date/Time'],
      FileDistribution: ['File Name', 'Document Type', 'Sender', 'Recipient', 'Time Sent', 'Time Received', 'Department/Subdepartment'],
      UsersPerDepartment: ['Department', 'User Count'],
      DocumentCopies: ['File Name', 'Copy Count', 'Offices with Copy', 'Physical Duplicates'],
      PendingRequests: ['File Name', 'Requester', "Requester's Department", 'Physical Storage'],
      RetrievalHistory: ['Transaction ID', 'Type', 'Status', 'Time', 'User', 'File Name', 'Department', 'Physical Storage'],
      AccessHistory: ['Transaction ID', 'Time', 'User', 'File Name', 'Type', 'Department'],
    };

    let csvContent = headers[chartType].map(escapeCsvField).join(',') + '\n';
    data.forEach(entry => {
      let row;
      switch (chartType) {
        case 'FileUploadTrends':
          row = [
            entry.document_name || '',
            entry.document_type || '',
            entry.uploader_name || '',
            (entry.uploader_department || 'None') + (entry.uploader_subdepartment ? ' / ' + entry.uploader_subdepartment : ''),
            entry.target_department_name || 'None',
            entry.upload_date ? new Date(entry.upload_date).toLocaleString() : 'N/A',
          ];
          break;
        case 'FileDistribution':
          row = [
            entry.document_name || '',
            entry.document_type || '',
            entry.sender_name || '',
            entry.receiver_name || '',
            entry.time_sent ? new Date(entry.time_sent).toLocaleString() : 'N/A',
            entry.time_received ? new Date(entry.time_received).toLocaleString() : 'N/A',
            (entry.department_name || 'None') + (entry.sub_department_name ? ' / ' + entry.sub_department_name : ''),
          ];
          break;
        case 'UsersPerDepartment':
          row = [
            entry.department_name || '',
            entry.user_count != null ? entry.user_count : 0,
          ];
          break;
        case 'DocumentCopies':
          row = [
            entry.file_name || '',
            entry.copy_count != null ? entry.copy_count : 0,
            entry.offices_with_copy || 'None',
            entry.physical_duplicates || 'None',
          ];
          break;
        case 'PendingRequests':
          row = [
            entry.file_name || '',
            entry.requester_name || '',
            (entry.requester_department || 'None') + (entry.requester_subdepartment ? ' / ' + entry.requester_subdepartment : ''),
            entry.storage_location || 'None',
          ];
          break;
        case 'RetrievalHistory':
          row = [
            entry.transaction_id != null ? entry.transaction_id : '',
            entry.type || '',
            entry.status || '',
            entry.time ? new Date(entry.time).toLocaleString() : 'N/A',
            entry.user_name || '',
            entry.file_name || '',
            entry.department_name || 'None',
            entry.storage_location || 'None',
          ];
          break;
        case 'AccessHistory':
          row = [
            entry.transaction_id != null ? entry.transaction_id : '',
            entry.time ? new Date(entry.time).toLocaleString() : 'N/A',
            entry.user_name || '',
            entry.file_name || '',
            entry.type || '',
            entry.department_name || 'None',
          ];
          break;
        default:
          alert('Download not implemented for this report type.');
          closeDownloadModal();
          return;
      }
      csvContent += row.map(escapeCsvField).join(',') + '\n';
    });

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${chartType}_Report.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  } else if (format === 'pdf') {
    const reportContent = generateReportContent(chartType);
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = reportContent;
    tempDiv.style.position = 'absolute';
    tempDiv.style.left = '-9999px';
    document.body.appendChild(tempDiv);

    const opt = {
      margin: 0.5,
      filename: `${chartType}_Report.pdf`,
      image: { type: 'png', quality: 1.0 },
      html2canvas: { scale: 2, useCORS: true },
      jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' },
    };

    html2pdf().from(tempDiv).set(opt).save().then(() => {
      document.body.removeChild(tempDiv);
    });
  } else {
    alert('Invalid format selected.');
  }
  closeDownloadModal();
};

// Sidebar Toggle
const toggleSidebar = () => {
  const sidebar = document.querySelector('.sidebar');
  const mainContent = document.querySelector('.main-content');
  const topNav = document.querySelector('.top-nav');

  sidebar.classList.toggle('minimized');
  mainContent.classList.toggle('resized');
  topNav.classList.toggle('resized');
};

// Modal Functions
const openModal = (chartType) => {
  currentChartType = chartType;
  currentPage = 1;
  const modal = document.getElementById('dataTableModal');
  const modalTitle = document.getElementById('modalTitle');
  modalTitle.textContent = CHART_CONFIG[chartType].title || chartType;
  renderTable();
  modal.style.display = 'flex';
};

const closeModal = () => {
  const modal = document.getElementById('dataTableModal');
  modal.style.display = 'none';
  document.getElementById('modalTable').innerHTML = '';
};

const openDownloadModal = (chartType) => {
  currentChartType = chartType;
  const modal = document.getElementById('downloadFormatModal');
  const modalTitle = document.getElementById('downloadModalTitle');
  modalTitle.textContent = `Select Download Format for ${CHART_CONFIG[chartType].title || chartType} Report`;
  modal.style.display = 'flex';
};

const closeDownloadModal = () => {
  const modal = document.getElementById('downloadFormatModal');
  modal.style.display = 'none';
};

const updatePagination = () => {
  const itemsPerPageSelect = document.getElementById('itemsPerPage');
  itemsPerPage = itemsPerPageSelect.value === 'all' ? dashboardData[CHART_CONFIG[currentChartType].dataKey]?.length || 1 : parseInt(itemsPerPageSelect.value);
  currentPage = 1;
  renderTable();
};

const previousPage = () => {
  if (currentPage > 1) {
    currentPage--;
    renderTable();
  }
};

const nextPage = () => {
  const maxPage = Math.ceil(dashboardData[CHART_CONFIG[currentChartType].dataKey]?.length / itemsPerPage) || 1;
  if (currentPage < maxPage) {
    currentPage++;
    renderTable();
  }
};

const renderTable = () => {
  const modalTable = document.getElementById('modalTable');
  modalTable.innerHTML = generateTableContent(currentChartType, currentPage, itemsPerPage);

  const prevButton = document.getElementById('prevPage');
  const nextButton = document.getElementById('nextPage');
  const pageInfo = document.getElementById('pageInfo');

  const maxPage = Math.ceil(dashboardData[CHART_CONFIG[currentChartType].dataKey]?.length / itemsPerPage) || 1;
  prevButton.disabled = currentPage === 1;
  nextButton.disabled = currentPage === maxPage || !dashboardData[CHART_CONFIG[currentChartType].dataKey]?.length;
  pageInfo.textContent = `Page ${currentPage} of ${maxPage}`;
};

// Event Listeners
document.addEventListener('DOMContentLoaded', () => {
  // Initialize charts for chart-based containers only
  const charts = {
    fileUploadTrends: initializeChart('fileUploadTrendsChart', CHART_CONFIG.FileUploadTrends, dashboardData.fileUploadTrends),
    fileDistribution: initializeChart('fileDistributionChart', CHART_CONFIG.FileDistribution, dashboardData.fileDistribution),
    usersPerDepartment: initializeChart('usersPerDepartmentChart', CHART_CONFIG.UsersPerDepartment, dashboardData.usersPerDepartment),
    documentCopies: initializeChart('documentCopiesChart', CHART_CONFIG.DocumentCopies, dashboardData.documentCopies),
  };

  // Add click event listeners to chart containers
  document.querySelectorAll('.chart-container').forEach(container => {
    container.addEventListener('click', (e) => {
      if (e.target.closest('.chart-actions')) return;
      const chartType = container.dataset.chartType;
      if (chartType) openModal(chartType);
    });
  });

  // Initialize sidebar state
  const sidebar = document.querySelector('.sidebar');
  const mainContent = document.querySelector('.main-content');
  const topNav = document.querySelector('.top-nav');
  if (sidebar.classList.contains('minimized')) {
    mainContent.classList.add('resized');
    topNav.classList.add('resized');
  }

  // Add Escape key listener to close modals
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeModal();
      closeDownloadModal();
    }
  });
});

// Global variables
let currentPage = 1;
let itemsPerPage = ITEMS_PER_PAGE_OPTIONS[0];
let currentData = [];
let currentChartType = '';